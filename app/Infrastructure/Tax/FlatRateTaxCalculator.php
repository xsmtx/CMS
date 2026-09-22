<?php

declare(strict_types=1);

namespace App\Infrastructure\Tax;

use App\Domain\Tax\Contracts\TaxCalculator;
use App\Domain\Tax\TaxableSupply;
use App\Domain\Tax\TaxComponent;
use App\Domain\Tax\TaxResult;

/**
 * One rate, one name, everywhere.
 *
 * Enough for an installation that sells in one country, which is most of
 * them at the start. It knows nothing about any jurisdiction: the rate and
 * the name are configuration, and a business customer in another country
 * with a tax id is handled by the one rule the operator can state without a
 * tax adviser — charge nothing, and say why.
 */
final readonly class FlatRateTaxCalculator implements TaxCalculator
{
    public function __construct(
        private string $rate,
        private string $name = 'VAT',
        private ?string $countryCode = null,
        private bool $exemptBusinessesAbroad = false,
    ) {}

    public function calculate(TaxableSupply $supply): TaxResult
    {
        $zero = $supply->amount->multipliedBy(0);

        if ($supply->amount->isZero() || $supply->amount->isNegative()) {
            return TaxResult::none($zero);
        }

        if ($this->isExempt($supply)) {
            return TaxResult::none($zero, 'reverse_charge');
        }

        $amount = $supply->amount->percentage($this->rate);

        if ($amount->isZero()) {
            return TaxResult::none($zero);
        }

        return new TaxResult($amount, [
            new TaxComponent($this->name, $this->rate, $amount, $this->countryCode),
        ]);
    }

    private function isExempt(TaxableSupply $supply): bool
    {
        if (! $this->exemptBusinessesAbroad || ! $supply->isBusiness) {
            return false;
        }

        if ($supply->taxId === null || trim($supply->taxId) === '') {
            return false;
        }

        // A business abroad with a tax id accounts for the tax itself. In
        // the seller's own country it does not.
        return $this->countryCode !== null
            && $supply->countryCode !== null
            && strtoupper($supply->countryCode) !== strtoupper($this->countryCode);
    }
}
