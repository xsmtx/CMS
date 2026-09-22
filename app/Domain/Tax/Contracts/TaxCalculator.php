<?php

declare(strict_types=1);

namespace App\Domain\Tax\Contracts;

use App\Domain\Tax\TaxableSupply;
use App\Domain\Tax\TaxResult;

/**
 * Works out the tax on a supply.
 *
 * Core never implements a country's rules. A single-country installation
 * configures the flat-rate calculator; anything more sits behind this
 * contract as a module, so adding a jurisdiction never edits billing.
 */
interface TaxCalculator
{
    public function calculate(TaxableSupply $supply): TaxResult;
}
