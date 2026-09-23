<?php

declare(strict_types=1);

namespace App\Domain\Automation;

/**
 * One row a run acted on, and what it did.
 *
 * `subjectType` and `subjectId` are stored as strings rather than as a
 * model, because this object crosses into the domain layer and because a
 * run item outlives the row it describes — a terminated service that is
 * later deleted leaves its line in the history.
 */
final readonly class RunItem
{
    public function __construct(
        public ItemOutcome $outcome,
        public ?string $subjectType = null,
        public ?string $subjectId = null,
        public ?string $subjectLabel = null,
        public ?string $message = null,
    ) {}
}
