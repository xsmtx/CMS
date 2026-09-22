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
