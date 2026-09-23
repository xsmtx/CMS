<?php

declare(strict_types=1);

namespace App\Application\Reports;

use Carbon\CarbonImmutable;
use Throwable;

/**
 * The window a report covers.
 *
 * A value object because six reports take the same two dates and an operator
 * changes them once for all of them — and because "from" and "to" passed as
 * loose strings is how a report ends up covering a different period from the one
 * beside it.
 *
 * **Inclusive of both days**, which is what somebody typing two dates means. A
 * report of "1 September to 30 September" that stopped at midnight on the 30th
 * would quietly lose a day's revenue, and nobody would notice because the number
 * would still look plausible.
 */
final readonly class ReportPeriod
{
    private function __construct(
        public CarbonImmutable $from,
        public CarbonImmutable $to,
    ) {}

    /**
     * The last twelve months, which is the window "is this growing" is asked
     * over.
     */
    public static function trailingYear(?CarbonImmutable $now = null): self
    {
        $to = ($now ?? CarbonImmutable::now())->endOfDay();

        return new self($to->subYear()->startOfDay(), $to);
    }

    /**
     * What somebody typed, or the default.
     *
     * **A backwards period is read the way it was meant** rather than refused:
     * somebody who typed the dates the other way round meant the period between
     * them, and a report is not the place to argue about it. A malformed date
     * falls back to the default window, because an error page is a worse answer
     * than a year of figures.
     */
    public static function fromInput(?string $from, ?string $to, ?CarbonImmutable $now = null): self
    {
        $default = self::trailingYear($now);

        $start = self::parse($from) ?? $default->from;
        $end = self::parse($to) ?? $default->to;

        if ($start->greaterThan($end)) {
            [$start, $end] = [$end, $start];
        }

        return new self($start->startOfDay(), $end->endOfDay());
    }

    /**
     * The months this period touches, oldest first, as `YYYY-MM`.
     *
     * Built from the calendar rather than from the rows, so a quiet month is a
     * zero rather than a gap — a chart that skipped an empty month would
     * compress a year into nine and lie about the shape.
     *
     * @return list<string>
     */
    public function months(): array
    {
        $months = [];
        $cursor = $this->from->startOfMonth();

        while ($cursor->lessThanOrEqualTo($this->to)) {
            $months[] = $cursor->format('Y-m');
            $cursor = $cursor->addMonth();
        }

        return $months;
    }

    public function days(): int
    {
        return (int) $this->from->diffInDays($this->to, absolute: true) + 1;
    }

    /**
     * @return array{from: string, to: string}
     */
    public function toArray(): array
    {
        return ['from' => $this->from->toDateString(), 'to' => $this->to->toDateString()];
    }

    private static function parse(?string $value): ?CarbonImmutable
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse(trim($value));
        } catch (Throwable) {
            return null;
        }
    }
}
