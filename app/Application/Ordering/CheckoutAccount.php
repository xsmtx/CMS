<?php

declare(strict_types=1);

namespace App\Application\Ordering;

/**
 * The details a visitor gives at checkout.
 *
 * No password field anywhere. An account created here is reachable only
 * through the reset flow, which proves the address before it can be used
 * and keeps a credential out of an order form.
 */
final readonly class CheckoutAccount
{
    public function __construct(
        public string $firstName,
        public string $lastName,
        public string $email,
        public string $currencyCode,
        public ?string $company = null,
        public ?string $phone = null,
        public ?string $taxId = null,
        public ?string $addressLine = null,
        public ?string $city = null,
        public ?string $region = null,
        public ?string $postalCode = null,
        public ?string $countryCode = null,
        public bool $marketingOptIn = false,
    ) {}
}
