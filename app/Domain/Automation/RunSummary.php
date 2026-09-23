<?php

declare(strict_types=1);

namespace App\Domain\Automation;

/**
 * What one run examined and what it did about it.
 *
 * Immutable and accumulated by `with*()` rather than mutated, so a task
 * that forgets to reassign the result gets a wrong number in a test rather
 * than a silently shared object across two runs.
 *
 * `examined` is not `changed + skipped + failed` by construction: a task
 * may look at a row and decide it is not even a candidate, which is a skip
 * with a reason worth counting. The four numbers are kept separately so
 * the screen can print them without inferring anything.
 */
final readonly class RunSummary
{
    /**
     * @param  list<RunItem>  $items
     */
    public function __construct(
        public int $examined = 0,
        public int $changed = 0,
        public int $skipped = 0,
        public int $failed = 0,
        public array $items = [],
    ) {}

    public function examining(int $count = 1): self
    {
        return new self($this->examined + $count, $this->changed, $this->skipped, $this->failed, $this->items);
    }

    public function changing(RunItem $item): self
    {
        return new self(
            $this->examined,
            $this->changed + 1,
            $this->skipped,
            $this->failed,
            [...$this->items, $item],
        );
    }

    /**
     * Skips carry no detail row. A nightly sweep of ten thousand services
     * that changes four of them should write four rows, not ten thousand.
     */
    public function skipping(): self
    {
        return new self($this->examined, $this->changed, $this->skipped + 1, $this->failed, $this->items);
    }

    public function failing(RunItem $item): self
    {
        return new self(
            $this->examined,
            $this->changed,
            $this->skipped,
            $this->failed + 1,
            [...$this->items, $item],
        );
    }

    public function merge(self $other): self
    {
        return new self(
            $this->examined + $other->examined,
            $this->changed + $other->changed,
            $this->skipped + $other->skipped,
            $this->failed + $other->failed,
            [...$this->items, ...$other->items],
        );
    }

    public function didNothing(): bool
    {
        return $this->changed === 0 && $this->failed === 0;
    }
}
