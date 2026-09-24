<?php

declare(strict_types=1);

namespace App\Domain\Tax;

/**
 * What an operator typed on the tax rule form.
 *
 * A value object rather than an array, which is the house pattern for every save
 * in the catalog and exists for two reasons that both apply here: the mapping
 * from a request to columns happens once, in the open, and the use case cannot
 * be handed a key nobody has thought about.
 *
 * `ratePartsPerMillion` is an integer all the way from the form: the screen asks
 * for a percentage, the request turns `9.975` into 99,750 once, and nothing
 * downstream ever holds a rate as a float. Parts per million rather than basis
 * points because a real rate needed three decimal places and basis points give
 * two.
 */
final readonly class TaxRuleAttributes
{
    public function __construct(
        public string $name,
        public int $ratePartsPerMillion,
        public ?string $countryCode = null,
        public ?string $regionCode = null,
        public ?string $postcodePattern = null,
        public int $level = 1,
        public bool $compound = false,
        public TaxAppliesTo $appliesTo = TaxAppliesTo::All,
        public TaxCustomerKind $customerKind = TaxCustomerKind::All,
        public bool $exemptsValidatedBusiness = false,
        public ?string $exemptionNote = null,
        public int $priority = 0,
        public ?string $startsOn = null,
        public ?string $endsOn = null,
        public bool $isActive = true,
        public ?string $notes = null,
    ) {}
}
