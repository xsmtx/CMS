<?php

declare(strict_types=1);

namespace App\Application\Ordering;

/**
 * What checkout supplies beyond the cart itself.
 *
 * `expectedTotalMinor` is the number the browser last showed. Order
 * placement compares it against a fresh price and refuses on a mismatch: a
 * price that moved while the customer was reading the page is a
 * conversation, not a silent charge.
 */
final readonly class PlaceOrderRequest
{
    public function __construct(
        public string $customerId,
        public ?string $contactId = null,
        public bool $termsAccepted = false,
        public ?int $expectedTotalMinor = null,
        public ?string $ipAddress = null,
        public ?string $userAgent = null,
        public ?string $ipCountry = null,
        public ?string $notes = null,
    ) {}
}
