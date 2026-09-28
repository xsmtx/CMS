<?php

declare(strict_types=1);

namespace App\Domain\Infrastructure\Contracts;

use App\Domain\Billing\UsageReading;
use Carbon\CarbonImmutable;

/**
 * Something that can say how much was used (§25).
 *
 * A panel's bandwidth log, a CDN's report, an object store's billing export,
 * a switch counter somebody wrote a script around.
 *
 * **It answers quantities, never money.** What a gigabyte costs is the
 * seller's and it lives on the meter row; a source that returned an amount
 * would be a module setting prices, which is the one thing metering must not
 * be able to do.
 *
 * **It answers for a period that has ended.** A reading for a month still
 * running is a number that will change, and this platform is about to write
 * it onto an invoice that cannot (ADR 0023). A source asked about a period it
 * cannot describe answers with nothing for it, never with a partial figure.
 *
 * **A source that failed must throw.** An empty answer means "nothing was
 * used", which is a real and billable answer — a customer who used no
 * bandwidth owes nothing for it — so a source that is merely unreachable must
 * not be able to say it.
 */
interface UsageMeter extends InfrastructureAdapter
{
    /**
     * Everything this source measured in that period.
     *
     * @return list<UsageReading>
     */
    public function usage(CarbonImmutable $periodStart, CarbonImmutable $periodEnd): array;
}
