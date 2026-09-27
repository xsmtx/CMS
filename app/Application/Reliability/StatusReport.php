<?php

declare(strict_types=1);

namespace App\Application\Reliability;

use App\Domain\Reliability\IncidentState;
use App\Domain\Reliability\PublicStatusLevel;
use Carbon\CarbonImmutable;

/**
 * A status page, in one value.
 *
 * Open and resolved are separate lists rather than one sorted by state,
 * because they answer different questions and a customer asks only the first
 * one: *is it broken now*. The history underneath is why they trust the
 * answer.
 *
 * @phpstan-type PublishedUpdate array{
 *     state: IncidentState,
 *     body: string,
 *     writtenAt: CarbonImmutable,
 * }
 * @phpstan-type PublishedWindow array{
 *     title: string,
 *     body: string|null,
 *     startsAt: CarbonImmutable,
 *     endsAt: CarbonImmutable,
 *     isRunning: bool,
 * }
 * @phpstan-type PublishedIncident array{
 *     reference: string,
 *     title: string,
 *     state: IncidentState,
 *     startedAt: CarbonImmutable,
 *     resolvedAt: CarbonImmutable|null,
 *     updates: list<PublishedUpdate>,
 * }
 */
final readonly class StatusReport
{
    /**
     * @param  list<PublishedIncident>  $open
     * @param  list<PublishedIncident>  $history
     * @param  list<PublishedWindow>  $maintenance
     */
    public function __construct(
        public PublicStatusLevel $level,
        public array $open,
        public array $history,
        public CarbonImmutable $since,
        public array $maintenance = [],
    ) {}
}
