<?php

declare(strict_types=1);

namespace App\Domain\Billing;

/**
 * What a gateway can actually do.
 *
 * Asked rather than assumed, so the interface only offers a refund button
 * where a refund is possible and core never calls a method an adapter
 * cannot honour.
 */
final readonly class GatewayCapabilities
{
    /**
     * @param  list<string>  $currencies  ISO codes, or empty for "any"
     */
    public function __construct(
        public bool $refunds = false,
        public bool $partialRefunds = false,
        public bool $storedMethods = false,
        public bool $webhooks = false,

        /** The customer leaves the site and comes back. */
        public bool $redirects = false,

        /** The gateway can charge a stored method without the customer. */
        public bool $unattendedCharges = false,

        public array $currencies = [],
    ) {}

    public function supportsCurrency(string $code): bool
    {
        return $this->currencies === [] || in_array(strtoupper($code), $this->currencies, strict: true);
    }
}
