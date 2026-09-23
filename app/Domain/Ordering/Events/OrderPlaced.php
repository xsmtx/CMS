<?php

declare(strict_types=1);

namespace App\Domain\Ordering\Events;

/**
 * An order exists.
 *
 * Raised the moment an order is written, whether the customer placed it at
 * checkout or the desk took it over the phone. The confirmation page has
 * always told a customer "we have emailed a confirmation"; nothing sent one,
 * because there was no event for anybody to subscribe to.
 *
 * Carries identifiers rather than models, like every event here: the domain
 * layer holds no framework types, and a listener that runs later — or on
 * another worker — should read the row as it is now rather than as it was
 * when the event was made.
 *
 * Separate from `OrderPaid`, which is a different fact and reaches different
 * people. An order existing is a receipt; an order being paid is what makes
 * provisioning somebody's business.
 */
final readonly class OrderPlaced
{
    public function __construct(
        public string $orderId,
        public string $organizationId,
        public ?string $correlationId = null,
    ) {}
}
