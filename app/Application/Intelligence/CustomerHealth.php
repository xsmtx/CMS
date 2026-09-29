<?php

declare(strict_types=1);

namespace App\Application\Intelligence;

use App\Application\Reports\MoneyByCurrency;
use App\Domain\Intelligence\HealthSignal;
use App\Domain\Operations\OperationState;
use App\Domain\Provisioning\ServiceStatus;
use App\Infrastructure\Billing\Models\Invoice;
use App\Infrastructure\Billing\Models\PaymentMethod;
use App\Infrastructure\Intelligence\Models\LeakageFinding;
use App\Infrastructure\Operations\Models\Operation;
use App\Infrastructure\Provisioning\Models\Service;
use App\Infrastructure\Security\Models\AbuseCase;
use App\Infrastructure\Support\Models\Ticket;
use Carbon\CarbonImmutable;

/**
 * What is true of a customer that somebody should know (§21).
 *
 * **Signals, never a score.** §21 asks for this to be explainable, and the
 * only honest way to be explainable is not to compute the thing that would
 * need explaining: a number between 0 and 100 is a number somebody acts on
 * and nobody can reproduce, and the first argument about it is the last time
 * anyone opens the screen. Each signal carries its own arithmetic and its
 * own sentence, and there is no total anywhere in this class.
 *
 * **Nothing is stored.** These are questions about rows that already exist,
 * asked when somebody opens the screen — a table of them would be a cache
 * that disagrees with its source by the afternoon.
 *
 * **`across()` is the implementation and `for()` delegates to it.** Seven
 * questions asked once for a page of customers rather than seven per row: a
 * `count()` inside a loop is not a lazy load, so strict mode would never
 * have said a word about it.
 *
 * **A signal with nothing to say is absent.** A wall of zeroes is a wall
 * somebody stops reading, and the one figure that is not zero disappears
 * into it.
 */
final readonly class CustomerHealth
{
    /**
     * How near a card has to be to expiry before it is worth saying.
     *
     * Roughly one renewal away: a card expiring in six months is not news,
     * and one expiring next week is a renewal about to fail.
     */
    private const int CardWarningDays = 45;

    /**
     * @return list<CustomerSignal>
     */
    public function for(string $customerId): array
    {
        return $this->across([$customerId])[$customerId] ?? [];
    }

    /**
     * Every signal for every one of these customers.
     *
     * @param  list<string>  $customerIds
     * @return array<string, list<CustomerSignal>>
     */
    public function across(array $customerIds): array
    {
        if ($customerIds === []) {
            return [];
        }

        /** @var array<string, list<CustomerSignal>> $signals */
        $signals = [];

        foreach (
            [
                $this->overdue($customerIds),
                $this->failedServices($customerIds),
                $this->abuse($customerIds),
                $this->breachedTickets($customerIds),
                $this->failedOperations($customerIds),
                $this->expiringCards($customerIds),
                $this->notBilled($customerIds),
            ] as $found
        ) {
            foreach ($found as $customerId => $signal) {
                $signals[$customerId][] = $signal;
            }
        }

        return $signals;
    }

    /**
     * Issued, past its due date and not paid.
     *
     * Measured from the **due date** and by what is **outstanding**, which
     * is how aging has worked in this product since the reports were
     * written: an invoice issued ninety days ago on sixty-day terms is
     * thirty days overdue, and a partly paid one ages at its remainder.
     *
     * @param  list<string>  $customerIds
     * @return array<string, CustomerSignal>
     */
    private function overdue(array $customerIds): array
    {
        $now = CarbonImmutable::now();
        /** @var array<string, array{count: int, money: MoneyByCurrency, days: int}> $found */
        $found = [];

        foreach (
            Invoice::query()
                ->whereIn('customer_id', $customerIds)
                ->owed()
                ->whereNotNull('due_on')
                ->where('due_on', '<', $now->toDateString())
                ->get() as $invoice
        ) {
            $outstanding = $invoice->total_minor - $invoice->paid_minor;
            $customerId = $invoice->customer_id;

            if ($outstanding <= 0 || $customerId === null) {
                continue;
            }

            $found[$customerId] ??= ['count' => 0, 'money' => new MoneyByCurrency, 'days' => 0];
            $found[$customerId]['count']++;
            $found[$customerId]['money']->add($invoice->currency_code, $outstanding);
            $found[$customerId]['days'] = max(
                $found[$customerId]['days'],
                (int) $invoice->due_on?->diffInDays($now),
            );
        }

        return array_map(
            static fn (array $row): CustomerSignal => new CustomerSignal(
                HealthSignal::Overdue,
                $row['count'],
                $row['money'],
                $row['days'],
            ),
            $found,
        );
    }

    /**
     * @param  list<string>  $customerIds
     * @return array<string, CustomerSignal>
     */
    private function failedServices(array $customerIds): array
    {
        return $this->counted(
            Service::query()
                ->whereIn('customer_id', $customerIds)
                ->where('status', ServiceStatus::Failed->value)
                ->selectRaw('customer_id, count(*) as aggregate')
                ->groupBy('customer_id')
                // `toBase()` applies the global scopes and then hands back
                // the query builder, because `aggregate` is not a property
                // of the model and the Eloquent `pluck` insists it is one.
                ->toBase()
                ->pluck('aggregate', 'customer_id')
                ->all(),
            HealthSignal::ServiceFailed,
        );
    }

    /**
     * @param  list<string>  $customerIds
     * @return array<string, CustomerSignal>
     */
    private function abuse(array $customerIds): array
    {
        return $this->counted(
            AbuseCase::query()
                ->whereIn('customer_id', $customerIds)
                ->open()
                ->selectRaw('customer_id, count(*) as aggregate')
                ->groupBy('customer_id')
                // `toBase()` applies the global scopes and then hands back
                // the query builder, because `aggregate` is not a property
                // of the model and the Eloquent `pluck` insists it is one.
                ->toBase()
                ->pluck('aggregate', 'customer_id')
                ->all(),
            HealthSignal::AbuseOpen,
        );
    }

    /**
     * @param  list<string>  $customerIds
     * @return array<string, CustomerSignal>
     */
    private function breachedTickets(array $customerIds): array
    {
        return $this->counted(
            Ticket::query()
                ->whereIn('customer_id', $customerIds)
                ->breachingSla()
                ->selectRaw('customer_id, count(*) as aggregate')
                ->groupBy('customer_id')
                // `toBase()` applies the global scopes and then hands back
                // the query builder, because `aggregate` is not a property
                // of the model and the Eloquent `pluck` insists it is one.
                ->toBase()
                ->pluck('aggregate', 'customer_id')
                ->all(),
            HealthSignal::TicketBreached,
        );
    }

    /**
     * An operation that ended badly, or one waiting for a person.
     *
     * `manual_intervention` is a real end state rather than a failure with
     * a note (ADR 0032), and it is exactly the one nobody notices — so it
     * counts here beside the outright failures.
     *
     * @param  list<string>  $customerIds
     * @return array<string, CustomerSignal>
     */
    private function failedOperations(array $customerIds): array
    {
        $services = Service::query()
            ->whereIn('customer_id', $customerIds)
            ->pluck('customer_id', 'id');

        if ($services->isEmpty()) {
            return [];
        }

        $counts = [];

        foreach (
            Operation::query()
                ->where('subject_type', Service::class)
                ->whereIn('subject_id', $services->keys())
                ->whereIn('state', [
                    OperationState::Failed->value,
                    OperationState::ManualIntervention->value,
                ])
                ->whereNull('resolved_at')
                ->pluck('subject_id') as $subjectId
        ) {
            $customerId = $services[$subjectId] ?? null;

            if (is_string($customerId)) {
                $counts[$customerId] = ($counts[$customerId] ?? 0) + 1;
            }
        }

        return $this->counted($counts, HealthSignal::OperationFailed);
    }

    /**
     * The card the next renewal would be charged to, about to expire.
     *
     * Only the default one: a customer with four stored cards is not in
     * trouble because the one they stopped using in 2023 has lapsed.
     *
     * @param  list<string>  $customerIds
     * @return array<string, CustomerSignal>
     */
    private function expiringCards(array $customerIds): array
    {
        $now = CarbonImmutable::now();
        $limit = $now->addDays(self::CardWarningDays);
        /** @var array<string, int> $found */
        $found = [];

        foreach (
            PaymentMethod::query()
                ->whereIn('customer_id', $customerIds)
                ->where('is_default', true)
                ->get() as $method
        ) {
            if ($method->expiry_month === null || $method->expiry_year === null) {
                continue;
            }

            // A card is good until the end of its stated month, which is
            // the thing everybody gets wrong by a month.
            $expires = CarbonImmutable::create($method->expiry_year, $method->expiry_month, 1)
                ?->endOfMonth();

            if ($expires === null || $expires->greaterThan($limit)) {
                continue;
            }

            $remaining = max(0, (int) $now->diffInDays($expires, absolute: false));
            $found[$method->customer_id] = min($found[$method->customer_id] ?? $remaining, $remaining);
        }

        return array_map(
            static fn (int $days): CustomerSignal => new CustomerSignal(
                HealthSignal::CardExpiring,
                1,
                days: $days,
            ),
            $found,
        );
    }

    /**
     * @param  list<string>  $customerIds
     * @return array<string, CustomerSignal>
     */
    private function notBilled(array $customerIds): array
    {
        /** @var array<string, array{count: int, money: MoneyByCurrency}> $found */
        $found = [];

        foreach (
            LeakageFinding::query()
                ->whereIn('customer_id', $customerIds)
                ->open()
                ->get() as $finding
        ) {
            $customerId = $finding->customer_id;

            if ($customerId === null) {
                continue;
            }

            $found[$customerId] ??= ['count' => 0, 'money' => new MoneyByCurrency];
            $found[$customerId]['count']++;
            $found[$customerId]['money']->add($finding->currency_code, $finding->amount_minor);
        }

        return array_map(
            static fn (array $row): CustomerSignal => new CustomerSignal(
                HealthSignal::NotBilled,
                $row['count'],
                $row['money'],
            ),
            $found,
        );
    }

    /**
     * @param  array<string, mixed>  $counts
     * @return array<string, CustomerSignal>
     */
    private function counted(array $counts, HealthSignal $signal): array
    {
        $found = [];

        foreach ($counts as $customerId => $count) {
            $found[(string) $customerId] = new CustomerSignal($signal, (int) $count);
        }

        return $found;
    }
}
