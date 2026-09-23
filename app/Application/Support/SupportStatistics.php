<?php

declare(strict_types=1);

namespace App\Application\Support;

use App\Domain\Support\TicketStatus;
use App\Infrastructure\Support\Models\Department;
use App\Infrastructure\Support\Models\Ticket;
use App\Infrastructure\Support\Models\TicketReply;
use App\Support\Organizations\OrganizationContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * What the desk did over a period.
 *
 * Counted from the rows every time rather than kept in a summary table.
 * The alternative — a nightly roll-up — is a second copy of the truth that
 * goes wrong quietly, and at this size the count is a few indexed queries.
 *
 * **First response time is the median, not the mean.** One ticket answered
 * after a fortnight because a customer went on holiday drags an average
 * into a number that describes nothing; the median says what most people
 * actually waited.
 */
final readonly class SupportStatistics
{
    public function __construct(private OrganizationContext $organizations) {}

    /**
     * @return array<string, mixed>
     */
    public function forPeriod(string $period): array
    {
        [$from, $to] = $this->window($period);

        $opened = Ticket::query()->whereBetween('created_at', [$from, $to]);
        $resolved = Ticket::query()->whereBetween('resolved_at', [$from, $to]);

        return [
            'period' => $period,
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'opened' => (clone $opened)->count(),
            'resolved' => (clone $resolved)->count(),
            'replies' => TicketReply::query()
                ->whereBetween('created_at', [$from, $to])
                ->where('is_internal', false)
                ->count(),
            // Outstanding is asked of now, not of the window: "how many are
            // waiting" has no meaning inside last month.
            'awaiting' => Ticket::query()->awaitingUs()->count(),
            'breaching' => Ticket::query()->breachingSla()->count(),
            'medianFirstResponseMinutes' => $this->medianFirstResponse($from, $to),
            'byStatus' => $this->byStatus(),
            'byDepartment' => $this->byDepartment($from, $to),
            'daily' => $this->daily($from, $to),
        ];
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function periods(): array
    {
        return [
            ['value' => 'today', 'label' => 'Today'],
            ['value' => 'yesterday', 'label' => 'Yesterday'],
            ['value' => 'this_week', 'label' => 'This Week'],
            ['value' => 'this_month', 'label' => 'This Month'],
            ['value' => 'last_month', 'label' => 'Last Month'],
        ];
    }

    /**
     * The organization every raw count is narrowed to.
     *
     * `DB::table()` carries no global scope, so the boundary the models
     * apply for free is applied by hand here — and a statistics screen
     * that counted another reseller's tickets would be the worst possible
     * place to leak one.
     */
    private function boundary(): ?string
    {
        return $this->organizations->id();
    }

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function window(string $period): array
    {
        $now = CarbonImmutable::now();

        return match ($period) {
            'yesterday' => [$now->subDay()->startOfDay(), $now->subDay()->endOfDay()],
            'this_week' => [$now->startOfWeek(), $now->endOfWeek()],
            'last_month' => [$now->subMonth()->startOfMonth(), $now->subMonth()->endOfMonth()],
            'this_month' => [$now->startOfMonth(), $now->endOfMonth()],
            default => [$now->startOfDay(), $now->endOfDay()],
        };
    }

    /**
     * The middle wait, in minutes.
     *
     * Read in PHP rather than in SQL because MariaDB has no median, and the
     * window is a day to a month of tickets rather than a table scan.
     */
    private function medianFirstResponse(CarbonImmutable $from, CarbonImmutable $to): ?int
    {
        $waits = Ticket::query()
            ->whereBetween('created_at', [$from, $to])
            ->whereNotNull('first_responded_at')
            ->get(['created_at', 'first_responded_at'])
            ->map(static fn (Ticket $ticket): int => (int) $ticket->created_at
                ?->diffInMinutes($ticket->first_responded_at))
            ->sort()
            ->values();

        if ($waits->isEmpty()) {
            return null;
        }

        return (int) $waits[intdiv($waits->count(), 2)];
    }

    /**
     * Everything open, by where it is.
     *
     * Asked of now rather than of the window, because a queue is a present
     * tense question.
     *
     * @return list<array{label: string, value: int}>
     */
    private function byStatus(): array
    {
        $counts = DB::table('tickets')
            ->when($this->boundary(), fn ($query, $id) => $query->where('organization_id', $id))
            ->select('status')
            ->selectRaw('count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        return array_values(array_map(
            static fn (TicketStatus $status): array => [
                'label' => (string) __($status->labelKey()),
                'value' => (int) ($counts[$status->value] ?? 0),
            ],
            TicketStatus::cases(),
        ));
    }

    /**
     * @return list<array{label: string, value: int}>
     */
    private function byDepartment(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $counts = DB::table('tickets')
            ->when($this->boundary(), fn ($query, $id) => $query->where('organization_id', $id))
            ->whereBetween('created_at', [$from, $to])
            ->select('department_id')
            ->selectRaw('count(*) as total')
            ->groupBy('department_id')
            ->pluck('total', 'department_id');

        $rows = Department::query()
            ->orderBy('name')
            ->get()
            ->map(static fn (Department $department): array => [
                'label' => $department->name,
                'value' => (int) ($counts[$department->id] ?? 0),
            ])
            ->all();

        // Tickets nobody routed. Shown rather than dropped: an unassigned
        // queue is the one somebody has to notice.
        $unassigned = (int) ($counts[''] ?? 0) + (int) ($counts['0'] ?? 0);

        if ($unassigned > 0) {
            $rows[] = ['label' => 'Unassigned', 'value' => $unassigned];
        }

        return array_values($rows);
    }

    /**
     * Opened per day across the window, for the chart.
     *
     * Every day in the window appears, including the empty ones: a chart
     * that skipped quiet days would compress a fortnight into a week and
     * lie about the shape.
     *
     * @return list<array{label: string, value: int}>
     */
    private function daily(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $counts = DB::table('tickets')
            ->when($this->boundary(), fn ($query, $id) => $query->where('organization_id', $id))
            ->whereBetween('created_at', [$from, $to])
            ->select(DB::raw('date(created_at) as day'))
            ->selectRaw('count(*) as total')
            ->groupBy('day')
            ->pluck('total', 'day');

        $rows = [];
        $cursor = $from->startOfDay();

        while ($cursor <= $to) {
            $key = $cursor->toDateString();

            $rows[] = ['label' => $cursor->format('j M'), 'value' => (int) ($counts[$key] ?? 0)];
            $cursor = $cursor->addDay();
        }

        return $rows;
    }
}
