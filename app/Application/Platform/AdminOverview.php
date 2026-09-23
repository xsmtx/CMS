<?php

declare(strict_types=1);

namespace App\Application\Platform;

use App\Domain\Billing\InvoiceStatus;
use App\Domain\Billing\TransactionKind;
use App\Domain\Catalog\BillingCycle;
use App\Domain\Crm\CancellationStatus;
use App\Domain\Domains\DomainStatus;
use App\Domain\Operations\OperationState;
use App\Domain\Ordering\OrderStatus;
use App\Domain\Provisioning\ServiceStatus;
use App\Domain\Shared\Money;
use App\Infrastructure\Audit\Models\AuditLog;
use App\Infrastructure\Billing\Models\Invoice;
use App\Infrastructure\Crm\Models\CancellationRequest;
use App\Infrastructure\Domains\Models\Domain;
use App\Infrastructure\Operations\Models\Operation;
use App\Infrastructure\Ordering\Models\Order;
use App\Infrastructure\Provisioning\Models\Server;
use App\Infrastructure\Provisioning\Models\Service;
use App\Infrastructure\Support\Models\Ticket;
use App\Support\Identity\CurrentActor;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * What an operator needs to know before they touch anything.
 *
 * **Grouped, not a card per metric** (Handoff #3 §3). A dashboard of twelve
 * equal tiles is a dashboard where nothing is more important than anything
 * else, and the reason people stop opening one is that it never tells them
 * what to do. This assembles four things in order of what an operator acts
 * on: what needs attention, what the business is doing, whether the
 * infrastructure is up, and what just happened.
 *
 * **Counted, never listed.** Every figure here is an aggregate over an
 * indexed column. A dashboard that loaded rows to count them is a dashboard
 * that gets slower every month it is used, and this one is opened first
 * every morning by everybody at once.
 *
 * **Permission-gated block by block.** A support agent opening this sees
 * tickets and not revenue, because `can()` is asked before the query runs
 * rather than after — a number computed and then hidden is a number that
 * still cost a query and still reached the browser's memory.
 *
 * The organization boundary needs no help: every model here carries it, so a
 * reseller's dashboard counts a reseller's customers.
 */
final readonly class AdminOverview
{
    public function __construct(private CurrentActor $actor) {}

    /**
     * @return array<string, mixed>
     */
    public function handle(): array
    {
        return [
            'attention' => $this->attention(),
            'headline' => $this->headline(),
            'infrastructure' => $this->infrastructure(),
            'revenue' => $this->revenue(),
            'activity' => $this->activity(),
        ];
    }

    /**
     * The rows that are somebody's job today.
     *
     * **Only what is actually wrong.** A list padded with zeroes is a list an
     * operator learns to skim, and then the one row that mattered is skimmed
     * with it. An empty list is a good morning, and the screen says so.
     *
     * Ordered by how expensive it is to ignore: money that has not arrived,
     * then a promise being broken, then something that failed, then something
     * waiting on a decision.
     *
     * @return list<array<string, mixed>>
     */
    private function attention(): array
    {
        $rows = [];

        if ($this->actor->can('billing.invoices.view')) {
            $overdue = Invoice::query()->where('status', InvoiceStatus::Overdue->value);
            $count = (clone $overdue)->count();

            if ($count > 0) {
                $rows[] = $this->row(
                    'invoices.overdue',
                    $count,
                    'Overdue invoices',
                    'danger',
                    '/admin/invoices?status=overdue',
                    'invoice',
                );
            }
        }

        if ($this->actor->can('support.tickets.view')) {
            $breaching = Ticket::query()->breachingSla()->count();

            if ($breaching > 0) {
                $rows[] = $this->row(
                    'tickets.breaching',
                    $breaching,
                    'Tickets past their promise',
                    'danger',
                    '/admin/support?breaching=1',
                    'ticket',
                );
            }
        }

        if ($this->actor->can('services.view')) {
            $failed = Service::query()->where('status', ServiceStatus::Failed->value)->count();

            if ($failed > 0) {
                $rows[] = $this->row(
                    'services.failed',
                    $failed,
                    'Services that failed to set up',
                    'critical',
                    '/admin/services?status=failed',
                    'services',
                );
            }
        }

        if ($this->actor->can('operations.view')) {
            $stuck = Operation::query()
                ->whereIn('state', [
                    OperationState::Failed->value,
                    OperationState::ManualIntervention->value,
                ])
                ->count();

            if ($stuck > 0) {
                $rows[] = $this->row(
                    'operations.stuck',
                    $stuck,
                    'Operations needing a hand',
                    'critical',
                    '/admin/operations',
                    'automation',
                );
            }
        }

        if ($this->actor->can('orders.review')) {
            $held = Order::query()
                ->whereIn('status', [
                    OrderStatus::FraudReview->value,
                    OrderStatus::PaymentReview->value,
                ])
                ->count();

            if ($held > 0) {
                $rows[] = $this->row(
                    'orders.held',
                    $held,
                    'Orders held for review',
                    'warning',
                    '/admin/orders/review',
                    'orders',
                );
            }
        }

        if ($this->actor->can('domains.view')) {
            $expiring = Domain::query()
                ->where('status', DomainStatus::Active->value)
                ->whereNotNull('expires_on')
                ->whereDate('expires_on', '<=', CarbonImmutable::now()->addDays(30)->toDateString())
                ->count();

            if ($expiring > 0) {
                $rows[] = $this->row(
                    'domains.expiring',
                    $expiring,
                    'Domains expiring within 30 days',
                    'warning',
                    '/admin/domains?status=active',
                    'domains',
                );
            }
        }

        if ($this->actor->can('services.view')) {
            $cancelling = CancellationRequest::query()
                ->where('status', CancellationStatus::Pending->value)
                ->count();

            if ($cancelling > 0) {
                $rows[] = $this->row(
                    'cancellations.pending',
                    $cancelling,
                    'Cancellation requests waiting',
                    'warning',
                    '/admin/cancellations',
                    'note',
                );
            }
        }

        return $rows;
    }

    /**
     * @return array<string, mixed>
     */
    private function row(
        string $key,
        int $count,
        string $label,
        string $tone,
        string $href,
        string $icon,
    ): array {
        return ['key' => $key, 'count' => $count, 'label' => $label, 'tone' => $tone, 'href' => $href, 'icon' => $icon];
    }

    /**
     * The four figures §3 names, and nothing else.
     *
     * A fifth would have to displace one of these, which is the discipline a
     * headline strip is for.
     *
     * @return list<array<string, mixed>>
     */
    private function headline(): array
    {
        $figures = [];

        if ($this->actor->can('services.view')) {
            $figures[] = [
                'key' => 'services',
                'label' => 'Active services',
                'value' => (string) Service::query()
                    ->where('status', ServiceStatus::Active->value)
                    ->count(),
                'href' => '/admin/services?status=active',
            ];
        }

        if ($this->actor->can('billing.invoices.view')) {
            $mrr = $this->monthlyRecurring();

            $figures[] = [
                'key' => 'mrr',
                'label' => 'Monthly recurring',
                'value' => $mrr->format(app()->getLocale()),
                'href' => '/admin/transactions',
                'hint' => 'Active services, normalised to a month.',
            ];
        }

        if ($this->actor->can('support.tickets.view')) {
            $figures[] = [
                'key' => 'tickets',
                'label' => 'Awaiting us',
                'value' => (string) Ticket::query()->awaitingUs()->count(),
                'href' => '/admin/support',
            ];
        }

        if ($this->actor->can('operations.view')) {
            $figures[] = [
                'key' => 'interventions',
                'label' => 'Needs intervention',
                'value' => (string) Operation::query()
                    ->where('state', OperationState::ManualIntervention->value)
                    ->count(),
                'href' => '/admin/operations',
                // §3 says "Incidents". There is no incident model here yet —
                // it arrives with Handoff #2 — so this counts the thing that
                // is actually true today rather than borrowing the word.
                'hint' => 'Operations a person has to finish.',
            ];
        }

        return $figures;
    }

    /**
     * What the active book is worth in a month.
     *
     * Summed per cycle and divided by that cycle's months, in integer minor
     * units. A yearly service at 1200 counts as 100 a month; the division
     * truncates, which understates by at most a minor unit per cycle and is
     * the right direction for a number somebody might quote.
     *
     * Money never becomes a float on the way (ADR: integer minor units), and
     * mixed currencies are **not** added together — converting at display
     * time is the one thing this platform refuses to do, so the figure is
     * reported in the installation's own currency and the rest is ignored
     * rather than silently folded in.
     */
    private function monthlyRecurring(): Money
    {
        $currency = strtoupper((string) config('platform.crm.default_currency', 'TRY'));

        $rows = Service::query()
            ->where('status', ServiceStatus::Active->value)
            ->where('currency_code', $currency)
            ->toBase()
            ->select('billing_cycle')
            ->selectRaw('sum(recurring_minor) as total')
            ->groupBy('billing_cycle')
            ->get();

        $minor = 0;

        foreach ($rows as $row) {
            $cycle = BillingCycle::tryFrom((string) $row->billing_cycle);

            // A one-time line is not recurring revenue, and neither is a
            // cycle nobody recognises.
            if (! $cycle instanceof BillingCycle || $cycle->months() === 0) {
                continue;
            }

            $minor += intdiv((int) $row->total, $cycle->months());
        }

        return Money::ofMinor($minor, $currency);
    }

    /**
     * Whether the machines are up, without running every health check.
     *
     * The health page runs the checks; this reads rows. A dashboard that ran
     * a Redis round trip, an SMTP handshake and a provider API call on every
     * load would be a dashboard nobody could open during an incident, which
     * is the one time it is wanted.
     *
     * @return array<string, mixed>|null
     */
    private function infrastructure(): ?array
    {
        if (! $this->actor->can('infrastructure.view')) {
            return null;
        }

        $servers = Server::query()
            ->toBase()
            ->select('status')
            ->selectRaw('count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $total = (int) $servers->sum();

        return [
            'servers' => $total,
            'active' => (int) ($servers['active'] ?? 0),
            'unavailable' => $total - (int) ($servers['active'] ?? 0),
            'provisioning' => Service::query()
                ->where('status', ServiceStatus::Provisioning->value)
                ->count(),
            'suspended' => Service::query()
                ->where('status', ServiceStatus::Suspended->value)
                ->count(),
        ];
    }

    /**
     * Money in, by month, for the last twelve.
     *
     * Monthly rather than daily: the daily shape already exists on the
     * Transactions screen, and what a dashboard is asked is "are we growing",
     * which a day cannot answer.
     *
     * Every month in the window appears, including the empty ones — a chart
     * that skipped a quiet month would compress a year into nine and lie
     * about the shape.
     *
     * @return array<string, mixed>|null
     */
    private function revenue(): ?array
    {
        if (! $this->actor->can('billing.invoices.view')) {
            return null;
        }

        $currency = strtoupper((string) config('platform.crm.default_currency', 'TRY'));
        $from = CarbonImmutable::now()->startOfMonth()->subMonths(11);

        $kinds = array_map(
            static fn (TransactionKind $kind): string => $kind->value,
            array_filter(
                TransactionKind::cases(),
                static fn (TransactionKind $kind): bool => $kind->increasesPaid(),
            ),
        );

        $totals = DB::table('transactions')
            ->when($this->actor->organizationId(), fn ($query, $id) => $query->where('organization_id', $id))
            ->where('currency_code', $currency)
            ->whereIn('kind', array_values($kinds))
            ->where('occurred_at', '>=', $from)
            ->select(DB::raw("date_format(occurred_at, '%Y-%m') as period"))
            ->selectRaw('sum(amount_minor) as total')
            ->groupBy('period')
            ->pluck('total', 'period');

        $rows = [];
        $cursor = $from;
        $locale = app()->getLocale();

        while ($cursor <= CarbonImmutable::now()) {
            $key = $cursor->format('Y-m');

            $rows[] = [
                'label' => $cursor->format('M'),
                'value' => (int) ($totals[$key] ?? 0),
            ];

            $cursor = $cursor->addMonth();
        }

        $sum = array_sum(array_column($rows, 'value'));

        return [
            'months' => $rows,
            'currency' => $currency,
            'total' => Money::ofMinor((int) $sum, $currency)->format($locale),
        ];
    }

    /**
     * What just happened, from the audit trail.
     *
     * The trail rather than a feed of its own: every sensitive action already
     * writes one, and a second stream would be a second thing to remember to
     * write to.
     *
     * @return list<array<string, mixed>>|null
     */
    private function activity(): ?array
    {
        if (! $this->actor->can('platform.audit.view')) {
            return null;
        }

        return array_values(AuditLog::query()
            // The trail's own clock. `created_at` is not it: an audit row
            // records when the thing happened, which a queued job writes
            // later than it happened.
            ->latest('occurred_at')
            ->limit(8)
            ->get()
            ->map(static fn (AuditLog $entry): array => [
                'id' => $entry->id,
                'action' => $entry->action,
                'actor' => $entry->actor_label,
                'target' => $entry->target_label,
                'reason' => $entry->reason,
                'at' => $entry->occurred_at->toIso8601String(),
            ])
            ->all());
    }
}
