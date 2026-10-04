<?php

declare(strict_types=1);

namespace App\Domain\Ai;

/**
 * What a provider answered, and what it cost.
 *
 * The cost travels with the text because the usage row needs both and asking
 * the adapter twice would mean asking about a call that has finished. A
 * provider that does not report token counts answers null rather than zero —
 * "nobody said" and "it was free" are different, and a report that added the
 * nulls up as zeroes would understate the month in the one direction a cost
 * report must never be wrong in.
 *
 * `model` is what actually answered, not what was configured. A provider that
 * silently served a smaller model is a provider whose bill will not match the
 * usage table, and the row should say what happened rather than what was asked
 * for.
 */
final readonly class AiCompletion
{
    public function __construct(
        public string $text,
        public string $model,
        public ?int $promptTokens = null,
        public ?int $completionTokens = null,
    ) {}

    public function tokens(): ?int
    {
        if ($this->promptTokens === null && $this->completionTokens === null) {
            return null;
        }

        return ($this->promptTokens ?? 0) + ($this->completionTokens ?? 0);
    }
}
