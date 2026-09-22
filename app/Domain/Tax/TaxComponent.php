<?php

declare(strict_types=1);

namespace App\Domain\Tax;

use App\Domain\Shared\Money;

/**
 * One named tax on a supply.
 *
 * A component rather than a single rate, because more than one tax applies
 * to one line in plenty of places, and an invoice has to be able to name
 * each of them separately.
 */
final readonly class TaxComponent
{
    /**
     * @param  string  $rate  a decimal string such as "20" or "7.5", never a float
     */
    public function __construct(
        public string $name,
        public string $rate,
        public Money $amount,
        public ?string $jurisdiction = null,
    ) {}
}
