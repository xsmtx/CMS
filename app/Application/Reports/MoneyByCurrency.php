<?php

declare(strict_types=1);

namespace App\Application\Reports;

use App\Domain\Shared\Money;

/**
 * A figure that is really several figures, one per currency.
 *
 * Every monetary answer in this platform has this shape and every report needs
 * it, so it exists once. A reseller selling in lira and euros has two MRRs; a
 * report that added them would be a report with a number in it that means
 * nothing, and there is no rate anywhere in this product to make it mean
 * something.
 *
 * The formatting happens here rather than in the screen, because a decimal point
 * exists in exactly one place per figure and this is it — `Money::format()` knows
 * a yen has no decimals and a dinar has three, and a template doing its own
 * division would not.
 */
final class MoneyByCurrency
{
    /** @var array<string, int> */
    private array $minor = [];

    public function add(string $currency, int $minorUnits): self
    {
        $code = strtoupper($currency);

        $this->minor[$code] = ($this->minor[$code] ?? 0) + $minorUnits;

        return $this;
    }

    public function minorFor(string $currency): int
    {
        return $this->minor[strtoupper($currency)] ?? 0;
    }

    public function isEmpty(): bool
    {
        return $this->minor === [];
    }

    /**
     * @return list<string>
     */
    public function currencies(): array
    {
        return array_keys($this->minor);
    }

    /**
     * Largest first, because that is the one somebody is looking for.
     *
     * @return list<array{currency: string, amount: string, minor: int}>
     */
    public function toArray(?string $locale = null): array
    {
        $rows = [];

        foreach ($this->minor as $currency => $minorUnits) {
            $rows[] = [
                'currency' => $currency,
                'amount' => Money::ofMinor($minorUnits, $currency)->format($locale ?? 'en'),
                'minor' => $minorUnits,
            ];
        }

        usort($rows, static fn (array $a, array $b): int => abs($b['minor']) <=> abs($a['minor']));

        return $rows;
    }

    /**
     * The same figures multiplied, for turning a month into a year.
     *
     * Integer arithmetic on minor units, like everything else: twelve times a
     * month's minor units is a year's minor units exactly, and no float is
     * involved.
     */
    public function multipliedBy(int $factor): self
    {
        $scaled = new self;

        foreach ($this->minor as $currency => $minorUnits) {
            $scaled->add($currency, $minorUnits * $factor);
        }

        return $scaled;
    }
}
