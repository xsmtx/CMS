<?php

declare(strict_types=1);

namespace App\Application\Ordering;

use App\Application\Shared\SearchPattern;
use App\Domain\Ordering\OrderStatus;
use App\Infrastructure\Crm\Models\Customer;
use App\Infrastructure\Ordering\Models\Order;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/**
 * Finding an order, the way an operator actually looks for one.
 *
 * Lives here rather than in the controller for the reason the architecture
 * tests enforce: a controller that assembles a query is a controller that
 * will eventually own a rule. The customer and service searches made the
 * same move for the same reason.
 *
 * Two criteria are answered from somewhere other than the order, and both
 * deliberately. **Payment status** and **payment method** come from the
 * invoices the order raised: an order with a paid flag of its own would be
 * a second answer to a question the ledger already answers, and the two
 * would drift the first time somebody recorded a bank transfer.
 */
final readonly class SearchOrders
{
    /**
     * @param  array<string, mixed>  $criteria
     * @return LengthAwarePaginator<int, Order>
     */
    public function paginate(array $criteria): LengthAwarePaginator
    {
        $query = Order::query()->with([
            ...Customer::displayNameWith('customer'),
            // Everything the row reads. A list that lazy-loads is a list
            // that throws the first time it returns two of anything.
            'invoices.payments',
        ]);

        $status = $this->text($criteria, 'status');

        if ($status !== null && OrderStatus::tryFrom($status) instanceof OrderStatus) {
            $query->where('status', $status);
        }

        $number = $this->text($criteria, 'number');

        if ($number !== null) {
            // The order number and the id are one box: an operator pasting
            // either has the same question, and making them choose is
            // making them guess which one they were given.
            $like = SearchPattern::like($number);

            $query->where(fn (Builder $inner) => $inner
                ->where('number', 'like', $like)
                ->orWhere('id', $number));
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

        $gateway = $this->text($criteria, 'payment');

        if ($gateway !== null) {
            $query->whereHas('invoices', fn (Builder $invoices) => $invoices
                ->whereHas('payments', fn (Builder $payments) => $payments->where('gateway', $gateway)));
        }

        $from = $this->text($criteria, 'from');

        if ($from !== null) {
            $query->whereDate('placed_at', '>=', $from);
        }

        $to = $this->text($criteria, 'to');

        if ($to !== null) {
            $query->whereDate('placed_at', '<=', $to);
        }

        $amount = $this->text($criteria, 'amount');

        if ($amount !== null) {
            // Typed in the operator's own money and converted to the minor
            // units everything is stored in. The float exists for one
            // expression and never reaches a column (ADR: money is integer
            // minor units).
            $query->where('total_minor', (int) round(((float) str_replace(',', '.', $amount)) * 100));
        }

        $ip = $this->text($criteria, 'ip');

        if ($ip !== null) {
            $query->where('ip_address', 'like', SearchPattern::like($ip));
        }

        return $query->latest('placed_at')->latest()->paginate(25)->withQueryString();
    }

    /**
     * The gateways that have actually taken money here.
     *
     * Read from the payments rather than from the registry: a list offering
     * a gateway nobody has ever paid through returns nothing and blames the
     * operator for it.
     *
     * @return list<array{value: string, label: string}>
     */
    public function gateways(): array
    {
        return array_values(DB::table('payments')
            ->select('gateway')
            ->distinct()
            ->orderBy('gateway')
            ->pluck('gateway')
            ->map(static fn (string $gateway): array => ['value' => $gateway, 'label' => $gateway])
            ->all());
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
