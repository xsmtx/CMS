<?php

declare(strict_types=1);

namespace App\Domain\Tax;

/**
 * The answers about tax that are not a rate.
 *
 * Four of them, and each one changes an amount or what an amount means, which is
 * why they are settings an operator states rather than anything core infers.
 */
final readonly class TaxSettingsAttributes
{
    public function __construct(
        public bool $pricesIncludeTax = false,
        public TaxRounding $rounding = TaxRounding::PerLine,
        public ?string $taxIdLabel = null,
        public bool $requireTaxIdForBusiness = false,
        public ?string $exemptionNote = null,
    ) {}
}
