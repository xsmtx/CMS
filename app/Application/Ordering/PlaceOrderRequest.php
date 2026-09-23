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
        /**
         * Set when the desk took this order rather than the customer
         * placing it.
         *
         * It satisfies the terms check — somebody on the phone agreed to
         * something — **without** filling `terms_accepted_at`, because the
         * customer never clicked anything and that field is the one a
         * dispute turns on. Who took the order is in the audit trail
         * instead, which is where "a person did this" belongs.
         */
        public ?string $onBehalfBy = null,
        /**
         * Whether to announce that the order exists.
         *
         * True for a customer at checkout, who is looking at a page that
         * says a confirmation has been emailed. False when the desk took
         * the order and will hand it over itself — the order is written
         * either way, so what is skipped is the message, not the history.
         */
        public bool $notify = true,
    ) {}
}
