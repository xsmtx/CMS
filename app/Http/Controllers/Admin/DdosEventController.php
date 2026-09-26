<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Application\Reports\MoneyByCurrency;
use App\Domain\Infrastructure\Network\DdosVector;
use App\Http\Controllers\Controller;
use App\Infrastructure\Crm\Models\Customer;
use App\Infrastructure\Network\Models\DdosEvent;
use App\Infrastructure\Provisioning\Models\Service;
use App\Support\Errors\ForbiddenException;
use App\Support\Identity\CurrentActor;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Attacks, and what was behind the addresses.
 *
 * **Read-only.** Mitigation is somebody else's control plane, and asking it
 * to start or stop diverting a customer's traffic is a change with
 * consequences — it belongs behind a workflow rather than behind a button,
 * which is the same decision the firewall's write got.
 *
 * The one figure no scrubbing vendor can produce is on this page: what the
 * attacked services bill. It comes from `services.recurring_minor` and is
 * answered as `MoneyByCurrency`, because a total across currencies is a
 * number that means nothing and is exactly the number somebody would quote.
 */
final class DdosEventController extends Controller
{
    public function index(Request $request, CurrentActor $actor): Response
    {
        if (! $actor->can('network.ddos.view')) {
            throw new ForbiddenException(__('automation.errors.not_permitted'));
        }

        $runningOnly = $request->boolean('running');

        $events = DdosEvent::query()
            // `displayNameWith('customer')`, never `with('customer')` alone:
            // a customer with no company name falls back to its primary
            // contact, and a relation the caller did not anticipate is a lazy
            // load the first time two rows come back.
            ->with(Customer::displayNameWith('customer'))
            ->with('service')
            ->when($runningOnly, static fn ($query) => $query->running())
            ->latest('started_at')
            ->paginate(25)
            ->withQueryString();

        return Inertia::render('Admin/Network/Attacks', [
            'events' => [
                'data' => array_map($this->row(...), array_values($events->items())),
                'links' => $events->linkCollection()->toArray(),
                'currentPage' => $events->currentPage(),
                'lastPage' => $events->lastPage(),
                'total' => $events->total(),
            ],
            'filters' => ['running' => $runningOnly],
            'impact' => $this->impact(),
        ]);
    }

    /**
     * What the attacked services are worth, over the events on record.
     *
     * From the services rather than from the graph or from an invoice line:
     * the graph stores no money at all (ADR 0043) and a line copies a
     * description (ADR 0021), so grouping by either would be a figure that
     * disagreed with the billing.
     *
     * @return array{services: int, customers: int, recurring: list<array<string, mixed>>}
     */
    private function impact(): array
    {
        $serviceIds = DdosEvent::query()
            ->whereNotNull('service_id')
            ->distinct()
            ->pluck('service_id')
            ->all();

        $customers = DdosEvent::query()
            ->whereNotNull('customer_id')
            ->distinct()
            ->count('customer_id');

        $recurring = new MoneyByCurrency;

        // Only the columns the arithmetic needs. A partial select is safe
        // precisely because nothing renders a customer name from these rows —
        // the rule that bit orders and invoices twice is about *display*.
        Service::query()
            ->whereIn('id', $serviceIds)
            ->get(['currency_code', 'recurring_minor'])
            ->each(static function (Service $service) use ($recurring): void {
                $recurring->add($service->currency_code, $service->recurring_minor);
            });

        return [
            'services' => count($serviceIds),
            'customers' => $customers,
            'recurring' => $recurring->toArray(app()->getLocale()),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function row(DdosEvent $event): array
    {
        return [
            'id' => $event->id,
            'target' => $event->target_address,
            'customer' => $event->customer?->displayName(),
            'customerId' => $event->customer_id,
            'service' => $event->service?->name,
            'serviceId' => $event->service_id,
            'source' => $event->source,
            'startedAt' => $event->started_at->toIso8601String(),
            'endedAt' => $event->ended_at?->toIso8601String(),
            'durationSeconds' => $event->durationSeconds(),
            // Strings, because the column is a decimal: a float would answer
            // 11.699999999 for a reported 11.7 and an operator could not
            // reconcile it with the transit invoice that carried it.
            'peakGbps' => $event->peak_gbps,
            'peakMpps' => $event->peak_mpps,
            'vectors' => array_values(array_map(
                static fn (DdosVector $vector): array => [
                    'value' => $vector->value,
                    'label' => (string) __($vector->labelKey()),
                ],
                $event->vectorCases(),
            )),
            'mitigation' => $event->mitigation,
        ];
    }
}
