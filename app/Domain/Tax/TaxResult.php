<?php

declare(strict_types=1);

namespace App\Domain\Tax;

use App\Domain\Shared\Money;

/**
 * What a supply attracts.
 *
 * Carries a reason when nothing is due, because "no tax" and "reverse charge
 * because the customer gave a valid VAT number" look the same on a total and
 * are completely different on an invoice.
 *
 * `included` says whether the tax is **inside** the amount that was handed in
 * rather than on top of it. It lives on the answer rather than on the question
 * because only the calculator knows: whether a catalog price is gross is the
 * seller's setting, and a calculator that reads it is the one place that can
 * report it. A calculator that does not set it — every module's, and every
 * implementation written before this existed — says false, which is exactly the
 * behaviour they already had.
 *
 * Nothing may be added to a total when it is true. That is the entire point: an
 * inclusive price is the price the customer was shown.
 */
final readonly class TaxResult
{
    /**
     * @param  list<TaxComponent>  $components
     */
    public function __construct(
        public Money $total,
        public array $components = [],
        public ?string $exemptionReason = null,
        public bool $included = false,
    ) {}

    public static function none(Money $zero, ?string $reason = null): self
    {
        return new self($zero, [], $reason);
    }

    public function isZero(): bool
    {
        return $this->total->isZero();
    }

    /**
     * @return list<array{name: string, rate: string, amount: int, jurisdiction: string|null}>
     */
    public function toArray(): array
    {
        return array_map(
            static fn (TaxComponent $component): array => [
                'name' => $component->name,
                'rate' => $component->rate,
                'amount' => $component->amount->minorUnits,
                'jurisdiction' => $component->jurisdiction,
            ],
            $this->components,
        );
    }
}
