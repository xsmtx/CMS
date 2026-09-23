<?php

declare(strict_types=1);

namespace App\Application\Provisioning\Listeners;

use App\Application\Operations\WatchedDispatch;
use App\Application\Provisioning\CreateServicesForOrder;
use App\Domain\Operations\OperationType;
use App\Domain\Ordering\Events\OrderPaid;
use App\Domain\Provisioning\AutoSetup;
use App\Infrastructure\Ordering\Models\Order;
use App\Infrastructure\Provisioning\Jobs\ProvisionService;
use App\Infrastructure\Provisioning\Models\Service;
use App\Support\Correlation\CorrelationContext;

/**
 * A paid order becomes services, and the ones that can be set up
 * automatically are queued.
 *
 * Runs synchronously and does almost nothing: it writes rows and dispatches
 * jobs. The slow, failure-prone part is in the job, where it can be retried
 * without replaying the row creation.
 *
 * Nothing here is provisioned inside a request. Even the fastest control
 * panel is slower than a customer's patience, and a webhook that times out
 * waiting for WHM gets redelivered — which is how a platform creates two
 * accounts for one order.
 */
final readonly class StartFulfilment
{
    public function __construct(
        private CreateServicesForOrder $services,
        private CorrelationContext $correlation,
        private WatchedDispatch $dispatcher,
    ) {}

    public function handle(OrderPaid $event): void
    {
        $order = Order::query()
            ->withoutGlobalScope('organization')
            ->with(['items.options', 'items.product'])
            ->find($event->orderId);

        if (! $order instanceof Order) {
            return;
        }

        $services = $this->services->handle($order);

        foreach ($services as $service) {
            if (! $this->shouldProvision($service)) {
                continue;
            }

            $this->dispatcher->handle(
                OperationType::ServiceProvision,
                $service,
                new ProvisionService($service->id, $event->correlationId ?? $this->correlation->id()),
            );
        }
    }

    /**
     * Whether the platform sets this one up on its own.
     *
     * Three ways to answer no, and all of them are ordinary: the product
     * says an operator does it, no module is configured, or the customer
     * bought something that is not provisioned at all.
     */
    private function shouldProvision(Service $service): bool
    {
        if ($service->module === null || $service->module === '') {
            return false;
        }

        return $service->product?->auto_setup !== AutoSetup::None;
    }
}
