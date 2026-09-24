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

    /**
     * The same tax, for a different amount.
     *
     * Used where a total has to be reconciled against a figure that is already
     * fixed — an inclusive price is the number the customer was shown, so the
     * components are adjusted to add up to it rather than the price being
     * adjusted to match the components.
     */
    public function withAmount(Money $amount): self
    {
        return new self($this->name, $this->rate, $amount, $this->jurisdiction);
    }
}
