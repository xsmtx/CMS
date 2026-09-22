<?php

declare(strict_types=1);

namespace App\Domain\Ordering\Events;

/**
 * An order has been paid for.
 *
 * Carries identifiers rather than models, for two reasons: the domain layer
 * holds no framework types, and a listener that runs later — or on another
 * worker — should read the row as it is now rather than as it was when the
 * event was made.
 *
 * This is the seam that lets billing stay ignorant of provisioning. An
 * order reaching `paid` is a fact about ordering; deciding that the fact
 * means somebody should go and create a hosting account is provisioning's
 * business, and it subscribes rather than being called.
 */
final readonly class OrderPaid
{
    public function __construct(
        public string $orderId,
        public string $organizationId,
        public ?string $correlationId = null,
    ) {}
}
