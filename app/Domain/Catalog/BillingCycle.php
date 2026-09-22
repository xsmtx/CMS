<?php

declare(strict_types=1);

namespace App\Domain\Catalog;

use Carbon\CarbonImmutable;

/**
 * How often a product is charged.
 *
 * Metered is deliberately absent. It needs usage collection, rating and a
 * different invoice shape, none of which belong to the catalog, and adding
 * the member early would invite code that pretends it works.
 */
enum BillingCycle: string
{
    case OneTime = 'one_time';
    case Monthly = 'monthly';
    case Quarterly = 'quarterly';
    case SemiAnnually = 'semi_annually';
    case Annually = 'annually';
    case Biennially = 'biennially';
    case Triennially = 'triennially';

    public function labelKey(): string
    {
        return 'catalog.cycles.'.$this->value;
    }

    public function isRecurring(): bool
    {
        return $this !== self::OneTime;
    }

    /**
     * Length in months. Zero for one-time, which has no period at all.
     */
    public function months(): int
    {
        return match ($this) {
            self::OneTime => 0,
            self::Monthly => 1,
            self::Quarterly => 3,
            self::SemiAnnually => 6,
            self::Annually => 12,
            self::Biennially => 24,
            self::Triennially => 36,
        };
    }

    /**
     * When the next invoice is due, counting from a given date.
     *
     * Month arithmetic clamps rather than overflows: a service renewing on
     * the 31st renews on the 28th in February, not on the 3rd of March.
     */
    public function nextDueDate(CarbonImmutable $from): ?CarbonImmutable
    {
        if (! $this->isRecurring()) {
            return null;
        }

        return $from->addMonthsNoOverflow($this->months());
    }

    /**
     * Ordering for a price matrix: shortest period first, one-time last,
     * because a setup-only product reads oddly at the top of the list.
     */
    public function sortOrder(): int
    {
        return $this === self::OneTime ? 99 : $this->months();
    }

    /**
     * @return list<self>
     */
    public static function recurring(): array
    {
        return array_values(array_filter(
            self::cases(),
            static fn (self $cycle): bool => $cycle->isRecurring(),
        ));
    }
}
