<?php

declare(strict_types=1);

namespace App\Application\Import;

use App\Domain\Catalog\BillingCycle;
use App\Domain\Shared\Currency;
use App\Domain\Shared\Exceptions\UnknownCurrency;
use App\Domain\Shared\Money;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * The half-dozen conversions every mapper needs, in one place.
 *
 * They are here rather than repeated because each one has a trap in it, and a
 * trap solved eight times is a trap solved differently eight times.
 *
 * - **A legacy billing cycle is prose.** WHMCS writes `Monthly`, `Annually`,
 *   `Semi-Annually`, `Free Account`, `One Time`. Matched loosely, and an
 *   unrecognised one is `null` rather than a guess — a service imported at the
 *   wrong cycle bills the customer wrongly forever, which is worse than a row
 *   an operator has to look at.
 * - **A legacy date is `0000-00-00` about a third of the time.** MySQL's zero
 *   date is not a date and Carbon will happily parse it into the year zero, so
 *   it is caught here and becomes null.
 * - **Money arrives as `decimal(16,2)`** and becomes integer minor units
 *   exactly once, through `Money::ofDecimal`. No float touches it.
 */
final readonly class LegacyValues
{
    /**
     * A legacy cycle, or null when it is not one this platform can bill.
     */
    public static function cycle(string $legacy): ?BillingCycle
    {
        $normalised = strtolower(str_replace([' ', '-', '_'], '', trim($legacy)));

        return match ($normalised) {
            'monthly' => BillingCycle::Monthly,
            'quarterly' => BillingCycle::Quarterly,
            'semiannually', 'semiannual', 'sixmonthly' => BillingCycle::SemiAnnually,
            'annually', 'annual', 'yearly' => BillingCycle::Annually,
            'biennially', 'biennial', 'twoyearly' => BillingCycle::Biennially,
            'triennially', 'triennial', 'threeyearly' => BillingCycle::Triennially,
            'onetime', 'once', 'free', 'freeaccount' => BillingCycle::OneTime,
            default => null,
        };
    }

    /**
     * A legacy date, or null.
     *
     * `0000-00-00` is MySQL's way of saying "no date" and it is everywhere in a
     * real legacy database. Carbon parses it into the year zero without
     * complaint, which then becomes a renewal date two thousand years ago and a
     * dunning sweep that tries very hard.
     */
    public static function date(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) && ! $value instanceof CarbonImmutable) {
            return null;
        }

        if ($value instanceof CarbonImmutable) {
            return $value;
        }

        $trimmed = trim($value);

        if ($trimmed === '' || str_starts_with($trimmed, '0000-00-00')) {
            return null;
        }

        try {
            $parsed = CarbonImmutable::parse($trimmed);
        } catch (Throwable) {
            return null;
        }

        // A legacy system with a corrupt date can hold something that parses
        // and is nonsense. Anything before the web is not a hosting record.
        return $parsed->year < 1990 ? null : $parsed;
    }

    /**
     * An ISO currency code, or null when this platform does not know it.
     *
     * Null rather than a default, because a mapper substituting euros for a
     * currency it did not recognise would silently reprice somebody's invoice.
     */
    public static function currency(string $code, ?string $fallback = null): ?string
    {
        foreach ([$code, $fallback] as $candidate) {
            if ($candidate === null || trim($candidate) === '') {
                continue;
            }

            try {
                return Currency::of($candidate)->code;
            } catch (UnknownCurrency) {
                continue;
            }
        }

        return null;
    }

    /**
     * Integer minor units, from a legacy decimal.
     */
    public static function money(string $decimal, string $currency): Money
    {
        return Money::ofDecimal($decimal === '' ? '0' : $decimal, $currency);
    }
}
