<?php

declare(strict_types=1);

namespace App\Application\Billing;

use App\Application\Shared\SearchPattern;
use App\Domain\Billing\TransactionKind;
use App\Infrastructure\Billing\Models\Transaction;
use App\Infrastructure\Crm\Models\Customer;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/**
 * Finding a movement of money, the way somebody reconciling looks for one.
 *
 * Nobody browses a ledger. They arrive holding a bank line — a date, an
 * amount, a reference a gateway invented — and need the row that matches
 * it, so every criterion here is a thing that appears on a statement.
 */
final readonly class SearchTransactions
{
    /**
     * @param  array<string, mixed>  $criteria
     * @return LengthAwarePaginator<int, Transaction>
     */
    public function paginate(array $criteria): LengthAwarePaginator
    {
        return $this->query($criteria)
            ->with([...Customer::displayNameWith('customer'), 'invoice:id,number'])
            ->latest('occurred_at')
            ->latest('id')
            ->paginate(50)
            ->withQueryString();
    }

    /**
     * Money in and money out, per day, for the chart above the list.
     *
     * Drawn from the same criteria as the list, so narrowing the search
     * narrows the picture. A chart that ignored the filter would answer a
     * question nobody asked.
     *
     * Every day in the window appears, including the empty ones: a chart
     * that skipped quiet days would compress a fortnight into a week and
     * lie about the shape.
     *
     * @param  array<string, mixed>  $criteria
     * @return array{in: list<array{label: string, value: int}>, out: list<array{label: string, value: int}>, totalIn: int, totalOut: int, currency: string}
     */
    public function flow(array $criteria): array
    {
        [$from, $to] = $this->window($criteria);

        // `toBase()` rather than `DB::table()`: it applies the model's
        // global scopes first, so the organization boundary is on the
        // aggregate as well — and an aggregate is not a model property,
        // which is the other reason this cannot stay an Eloquent query.
        $rows = $this->query($criteria)
            ->whereBetween('occurred_at', [$from, $to])
            ->toBase()
            ->select(DB::raw('date(occurred_at) as day'), 'kind')
            ->selectRaw('sum(amount_minor) as total')
            ->groupBy('day', 'kind')
            ->get();

        $in = [];
        $out = [];

        foreach ($rows as $row) {
            $kind = TransactionKind::tryFrom((string) $row->kind);

            if (! $kind instanceof TransactionKind) {
                continue;
            }

            $day = (string) $row->day;
            $bucket = $kind->increasesPaid() ? 'in' : 'out';

            if ($bucket === 'in') {
                $in[$day] = ($in[$day] ?? 0) + (int) $row->total;
            } else {
                $out[$day] = ($out[$day] ?? 0) + (int) $row->total;
            }
        }

        $inRows = [];
        $outRows = [];
        $cursor = $from;

        while ($cursor <= $to) {
            $key = $cursor->toDateString();
            $label = $cursor->format('j M');

            $inRows[] = ['label' => $label, 'value' => $in[$key] ?? 0];
            $outRows[] = ['label' => $label, 'value' => $out[$key] ?? 0];
            $cursor = $cursor->addDay();
        }

        return [
            'in' => $inRows,
            'out' => $outRows,
            'totalIn' => array_sum($in),
            'totalOut' => array_sum($out),
            'currency' => $this->currency($criteria),
        ];
    }

    /**
     * The currencies this installation has actually moved money in.
     *
     * Read from the rows rather than from a list of every ISO code: an
     * operator choosing between two hundred currencies to record a Turkish
     * bank transfer is an operator choosing wrong occasionally.
     *
     * @return list<array{value: string, label: string}>
     */
    public function currencies(): array
    {
        $codes = Transaction::query()
            ->toBase()
            ->distinct()
            ->pluck('currency_code')
            ->map(static fn (mixed $code): string => strtoupper((string) $code))
            ->all();

        $default = strtoupper((string) config('platform.crm.default_currency', 'TRY'));
        $codes = array_values(array_unique([$default, ...$codes]));
        sort($codes);

        return array_map(
            static fn (string $code): array => ['value' => $code, 'label' => $code],
            $codes,
        );
    }

    /**
     * @param  array<string, mixed>  $criteria
     * @return Builder<Transaction>
     */
    private function query(array $criteria): Builder
    {
        $query = Transaction::query();

        $kind = $this->text($criteria, 'kind');

        if ($kind !== null && TransactionKind::tryFrom($kind) instanceof TransactionKind) {
            $query->where('kind', $kind);
        }

        $direction = $this->text($criteria, 'direction');

        if ($direction === 'in' || $direction === 'out') {
            // Direction is a property of the kind, not a column (ADR 0024).
            // Asked of the enum so the two can never disagree.
            $kinds = array_map(
                static fn (TransactionKind $case): string => $case->value,
                array_filter(
                    TransactionKind::cases(),
                    static fn (TransactionKind $case): bool => $case->increasesPaid() === ($direction === 'in'),
                ),
            );

            $query->whereIn('kind', array_values($kinds));
        }

        $client = $this->text($criteria, 'client');

        if ($client !== null) {
            $like = SearchPattern::like($client);

            $query->whereHas('customer', function (Builder $customer) use ($like): void {
                $customer->where('company_name', 'like', $like)
                    ->orWhere('legal_name', 'like', $like)
                    ->orWhereHas('contacts', function (Builder $contacts) use ($like): void {
                        $contacts->where('email', 'like', $like);

                        SearchPattern::name($contacts, $like);
                    });
            });
        }

        $reference = $this->text($criteria, 'reference');

        if ($reference !== null) {
            // The gateway's reference and our own row id are one box: an
            // operator pasting either has the same question.
            $like = SearchPattern::like($reference);

            $query->where(fn (Builder $inner) => $inner
                ->where('reference', 'like', $like)
                ->orWhere('id', $reference));
        }

        $invoice = $this->text($criteria, 'invoice');

        if ($invoice !== null) {
            $like = SearchPattern::like($invoice);

            $query->whereHas('invoice', fn (Builder $inner) => $inner
                ->where('number', 'like', $like)
                ->orWhere('id', $invoice));
        }

        $gateway = $this->text($criteria, 'gateway');

        if ($gateway !== null) {
            $query->where('gateway', $gateway);
        }

        $description = $this->text($criteria, 'description');

        if ($description !== null) {
            $query->where('description', 'like', SearchPattern::like($description));
        }

        $from = $this->text($criteria, 'from');

        if ($from !== null) {
            $query->whereDate('occurred_at', '>=', $from);
        }

        $to = $this->text($criteria, 'to');

        if ($to !== null) {
            $query->whereDate('occurred_at', '<=', $to);
        }

        $amount = $this->text($criteria, 'amount');

        if ($amount !== null) {
            // Typed in the operator's own money and converted to the minor
            // units everything is stored in. The float exists for one
            // expression and never reaches a column.
            $query->where('amount_minor', (int) round(((float) str_replace(',', '.', $amount)) * 100));
        }

        return $query;
    }

    /**
     * The window the chart covers: what was asked for, or the last 30 days.
     *
     * @param  array<string, mixed>  $criteria
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function window(array $criteria): array
    {
        $now = CarbonImmutable::now();
        $from = $this->text($criteria, 'from');
        $to = $this->text($criteria, 'to');

        $start = $from === null ? $now->subDays(29)->startOfDay() : CarbonImmutable::parse($from)->startOfDay();
        $end = $to === null ? $now->endOfDay() : CarbonImmutable::parse($to)->endOfDay();

        if ($start > $end) {
            $start = $end->subDays(29)->startOfDay();
        }

        // A chart of two years of days is a chart of grey mush. Beyond a
        // quarter the bars stop meaning anything at this width.
        if ($start->diffInDays($end) > 92) {
            $start = $end->subDays(92)->startOfDay();
        }

        return [$start, $end];
    }

    /**
     * What the chart's totals are counted in.
     *
     * The installation's own currency unless the search narrows to rows of
     * another. Summing two currencies into one bar would be the worst kind
     * of wrong: plausible, and off by a factor nobody can see.
     *
     * @param  array<string, mixed>  $criteria
     */
    private function currency(array $criteria): string
    {
        $codes = $this->query($criteria)
            ->toBase()
            ->distinct()
            ->limit(2)
            ->pluck('currency_code')
            ->all();

        return count($codes) === 1
            ? (string) $codes[0]
            : (string) config('platform.crm.default_currency', 'TRY');
    }

    /**
     * @param  array<string, mixed>  $criteria
     */
    private function text(array $criteria, string $key): ?string
    {
        $value = $criteria[$key] ?? null;

        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
