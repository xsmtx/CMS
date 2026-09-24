<?php

declare(strict_types=1);

namespace App\Domain\Billing;

/**
 * What a seller's billing terms are, as one value.
 *
 * A named object rather than an array, for the reason the tax attributes are
 * one: an `array<string, mixed>` handed to `create()` tells PHPStan nothing and
 * a caller that misspells a key finds out when a column is silently left at its
 * default.
 */
final readonly class BillingSettingsAttributes
{
    public function __construct(
        public int $dueDays,
        public int $lateFeeRatePartsPerMillion,
        public ?string $lateFeeLabel,
        public ?string $documentNote,
    ) {}
}
