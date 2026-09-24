<?php

declare(strict_types=1);

namespace App\Application\Infrastructure;

use App\Domain\Infrastructure\Relation;
use App\Infrastructure\Resources\Models\ResourceNode;
use Carbon\CarbonImmutable;

/**
 * One relationship a node has had, open or closed.
 *
 * `nodeIsContainer` is what lets one screen render both directions without
 * asking which it is looking at: "held by rack 4" and "held 192.0.2.10" are the
 * same row read from the two ends.
 */
final readonly class HistoryRow
{
    public function __construct(
        public ResourceNode $other,
        public Relation $relation,
        public bool $nodeIsContainer,
        public CarbonImmutable $observedAt,
        public ?CarbonImmutable $endedAt = null,
    ) {}

    public function isOpen(): bool
    {
        return $this->endedAt === null;
    }

    /**
     * How long it lasted, in whole days, or null while it is still true.
     *
     * Days rather than a formatted duration, because the presenter formats and
     * this is a number a test can assert.
     */
    public function days(): ?int
    {
        return $this->endedAt === null
            ? null
            : (int) $this->observedAt->diffInDays($this->endedAt, absolute: true);
    }
}
