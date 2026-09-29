<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Application\Intelligence\CustomerHealth;
use App\Application\Intelligence\CustomerSignal;
use App\Domain\Intelligence\HealthSignal;
use App\Http\Controllers\Controller;
use App\Infrastructure\Crm\Models\Customer;
use App\Support\Errors\ForbiddenException;
use App\Support\Identity\CurrentActor;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Which customers somebody should look at (§21).
 *
 * **There is no score and nothing is ranked by one.** A number between 0 and
 * 100 is a number somebody acts on and nobody can reproduce; the list is
 * ordered by the worst thing that is *true* of each customer, which is an
 * ordering this product already has words for.
 *
 * **Only customers with something to say are listed.** A page of accounts
 * with nothing against them is a page nobody reads twice, and the one
 * account that matters disappears into it. The empty state says so in
 * words rather than showing an empty table.
 *
 * It reads `crm.customers.view` rather than a permission of its own:
 * inventing one for a screen that shows what somebody may already see would
 * be a permission that only confuses.
 */
final class CustomerHealthController extends Controller
{
    /**
     * The order an operator wants: the worst thing first.
     *
     * @var list<string>
     */
    private const array Severity = ['critical', 'warning'];

    public function index(Request $request, CurrentActor $actor, CustomerHealth $health): Response
    {
        if (! $actor->can('crm.customers.view')) {
            throw new ForbiddenException(__('automation.errors.not_permitted'));
        }

        /*
         * Every customer is examined, because a signal is a question about
         * rows rather than a column to filter on — and `across()` asks each
         * question once rather than once per customer.
         */
        $customers = Customer::query()
            ->with(Customer::displayNameWith())
            ->limit(2000)
            ->get();

        // `array_values(...->all())`, not `->values()->all()`: only the
        // first narrows the type to a list for PHPStan.
        $signals = $health->across(array_values(array_map(
            static fn (Customer $customer): string => $customer->id,
            $customers->all(),
        )));

        $rows = [];

        foreach ($customers as $customer) {
            $found = $signals[$customer->id] ?? [];

            if ($found === []) {
                continue;
            }

            $rows[] = [
                'id' => $customer->id,
                'name' => $customer->displayName(),
                'href' => '/admin/customers/'.$customer->id,
                'worst' => $this->worst($found),
                'signals' => array_map($this->signal(...), $found),
            ];
        }

        usort($rows, function (array $a, array $b): int {
            $order = array_search($a['worst'], self::Severity, strict: true)
                <=> array_search($b['worst'], self::Severity, strict: true);

            // Within a severity, alphabetically — never by a count, which
            // would be the weighted total this screen refuses to compute.
            return $order !== 0 ? $order : strcasecmp((string) $a['name'], (string) $b['name']);
        });

        return Inertia::render('Admin/Intelligence/CustomerHealth', [
            'rows' => $rows,
            // Worded here too, for the reason the figures are.
            'examined' => trans_choice('intelligence.health.examined', $customers->count(), [
                'count' => $customers->count(),
            ]),
            'kinds' => array_map(
                static fn (HealthSignal $signal): array => [
                    'value' => $signal->value,
                    'label' => (string) __($signal->labelKey()),
                    'description' => (string) __($signal->descriptionKey()),
                ],
                HealthSignal::cases(),
            ),
        ]);
    }

    /**
     * The sentence beside a signal, in the reader's own language.
     *
     * A bare number would mean three invoices in one row and three days in
     * the next, under one heading that says neither.
     */
    private function figure(CustomerSignal $signal): string
    {
        [$key, $number] = match ($signal->signal) {
            HealthSignal::Overdue => ['invoices', $signal->count],
            HealthSignal::ServiceFailed => ['services', $signal->count],
            HealthSignal::OperationFailed => ['operations', $signal->count],
            HealthSignal::TicketBreached => ['tickets', $signal->count],
            // The one measured in time rather than in things.
            HealthSignal::CardExpiring => ['card', $signal->days ?? 0],
            HealthSignal::AbuseOpen => ['cases', $signal->count],
            HealthSignal::NotBilled => ['findings', $signal->count],
        };

        return trans_choice('intelligence.health.counts.'.$key, $number, [
            'count' => $number,
            'days' => $signal->days ?? 0,
        ]);
    }

    /**
     * @param  list<CustomerSignal>  $signals
     */
    private function worst(array $signals): string
    {
        foreach (self::Severity as $tone) {
            foreach ($signals as $signal) {
                if ($signal->signal->tone() === $tone) {
                    return $tone;
                }
            }
        }

        return 'warning';
    }

    /**
     * @return array<string, mixed>
     */
    private function signal(CustomerSignal $signal): array
    {
        return [
            // Two fields, always: the value for the tone and the word for
            // the screen.
            'kind' => $signal->signal->value,
            'label' => (string) __($signal->signal->labelKey()),
            'tone' => $signal->signal->tone(),
            'count' => $signal->count,
            'days' => $signal->days,
            /*
             * Worded here rather than in the browser. `useTranslations()`
             * has no `trans_choice`, so a count worded there reads as “1
             * invoices” — and a `switch` on the kind in the page would be a
             * second copy of a mapping the enum already owns.
             */
            'figure' => $this->figure($signal),
            // Money is a list, never a number.
            'money' => $signal->money?->toArray(app()->getLocale()),
        ];
    }
}
