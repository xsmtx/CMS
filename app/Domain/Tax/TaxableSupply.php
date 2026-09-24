<?php

declare(strict_types=1);

namespace App\Domain\Tax;

use App\Domain\Shared\Money;

/**
 * What is being taxed, and for whom.
 *
 * Deliberately says nothing about any jurisdiction's rules. Core's job is to
 * state the facts of the supply; deciding what tax those facts attract is
 * the calculator's, and a module's.
 */
final readonly class TaxableSupply
{
    public function __construct(
        /** The amount after any discount. Tax is charged on what is paid. */
        public Money $amount,

        /** ISO 3166-1 alpha-2, where the customer receives the supply. */
        public ?string $countryCode = null,

        public ?string $stateCode = null,
        public ?string $postalCode = null,

        /** A VAT or equivalent registration number, unvalidated here. */
        public ?string $taxId = null,

        public bool $isBusiness = false,

        /** Where the seller is established. */
        public ?string $supplierCountryCode = null,

        /**
         * Which kind of line this is, so a rule can be narrower than "all".
         *
         * Several countries tax a domain registration and a hosting account at
         * different rates, or exempt one of them. Added with a default so that
         * a caller which does not care — and an adapter written against the
         * older shape — is unaffected.
         */
        public TaxAppliesTo $appliesTo = TaxAppliesTo::All,
    ) {}

    public function currencyCode(): string
    {
        return $this->amount->currency->code;
    }
}
