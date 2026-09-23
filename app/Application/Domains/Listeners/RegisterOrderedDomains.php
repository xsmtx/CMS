<?php

declare(strict_types=1);

namespace App\Application\Domains\Listeners;

use App\Application\Domains\CreateDomainsForOrder;
use App\Application\Operations\WatchedDispatch;
use App\Domain\Operations\OperationType;
use App\Domain\Ordering\Events\OrderPaid;
use App\Infrastructure\Domains\Jobs\RegisterDomain;
use App\Infrastructure\Domains\Models\Domain;
use App\Infrastructure\Ordering\Models\Order;
use App\Support\Correlation\CorrelationContext;

/**
 * A paid order's domain lines become names, and the ones with a registrar
 * behind them are queued for registration.
 *
 * Subscribes to the same event as provisioning
 * ([ADR 0027](../../../../docs/adr/0027-contexts-meet-through-events.md)),
 * and neither knows the other exists. An order with a hosting plan and a
 * domain on it produces a service and a domain, from two listeners, with no
 * code anywhere that knows both things happened.
 */
final readonly class RegisterOrderedDomains
{
    public function __construct(
        private CreateDomainsForOrder $domains,
        private CorrelationContext $correlation,
        private WatchedDispatch $dispatcher,
    ) {}

    public function handle(OrderPaid $event): void
    {
        $order = Order::query()
            ->withoutGlobalScope('organization')
            ->with(['items', 'customer'])
            ->find($event->orderId);

        if (! $order instanceof Order) {
            return;
        }

        foreach ($this->domains->handle($order) as $domain) {
            if (! $this->shouldRegister($domain)) {
                continue;
            }

            $this->dispatcher->handle(
                OperationType::DomainRegister,
                $domain,
                new RegisterDomain($domain->id, $event->correlationId ?? $this->correlation->id()),
            );
        }
    }

    /**
     * A domain with no registrar waits for a person, which is a real
     * answer rather than a missing one.
     */
    private function shouldRegister(Domain $domain): bool
    {
        return $domain->registrar !== null && $domain->registrar !== '';
    }
}
