<?php

declare(strict_types=1);

namespace App\Application\Ordering;

use App\Domain\Domains\DomainOrderType;

/**
 * An order an operator is placing on somebody else's behalf.
 *
 * The three switches are what the desk decides about a sale that did not
 * come through the storefront: whether to confirm it now, whether to bill
 * for it now, and whether the customer should hear about any of it. They
 * are independent on purpose — "raise the invoice but do not email it yet"
 * is a normal thing to want on the phone.
 */
final readonly class PlaceOrderForCustomerRequest
{
    /**
     * @param  list<OrderLineRequest>  $lines
     * @param  list<string>  $domainAddons  dns_management, email_forwarding, id_protection
     */
    public function __construct(
        public string $customerId,
        public array $lines = [],
        public ?string $promotionCode = null,
        public ?string $gateway = null,
        public ?string $notes = null,
        public bool $confirm = true,
        public bool $generateInvoice = false,
        public bool $sendEmail = true,
        public ?DomainOrderType $domainAction = null,
        public ?string $domainName = null,
        public int $domainYears = 1,
        public array $domainAddons = [],
        public ?int $domainRegistrationOverrideMinor = null,
    ) {}

    public function wantsDomain(): bool
    {
        return $this->domainAction instanceof DomainOrderType
            && $this->domainName !== null
            && trim($this->domainName) !== '';
    }
}
