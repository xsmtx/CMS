<?php

declare(strict_types=1);

namespace App\Infrastructure\Tax;

use App\Domain\Shared\Money;
use App\Domain\Tax\Contracts\TaxCalculator;
use App\Domain\Tax\TaxableSupply;
use App\Domain\Tax\TaxResult;

/**
 * Charges nothing.
 *
 * The default, and the honest one: an installation that has not been told
 * its tax rules must not guess at them. An operator choosing this is
 * choosing to handle tax outside the platform, which is a real answer.
 */
final readonly class NoTaxCalculator implements TaxCalculator
{
    public function calculate(TaxableSupply $supply): TaxResult
    {
        return TaxResult::none(Money::zero($supply->amount->currency));
    }
}
