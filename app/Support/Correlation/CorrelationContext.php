<?php

declare(strict_types=1);

namespace App\Support\Correlation;

use Illuminate\Support\Facades\Context;

/**
 * Reads and writes the current correlation identifier.
 *
 * Laravel's Context is the storage of record because it is serialised into
 * queued jobs automatically and is appended to every log record, so a job
 * that runs minutes later still reports the identifier of the request that
 * dispatched it.
 */
final readonly class CorrelationContext
{
    public function __construct(private string $contextKey) {}

    public function set(CorrelationId $id): void
    {
        Context::add($this->contextKey, $id->value);
    }

    public function id(): ?string
    {
        $value = Context::get($this->contextKey);

        return is_string($value) ? $value : null;
    }

    public function current(): ?CorrelationId
    {
        return CorrelationId::tryFrom($this->id());
    }

    /**
     * Returns the current identifier, creating and storing one when the work
     * did not originate from an HTTP request (console commands, scheduled
     * tasks, tests).
     */
    public function idOrGenerate(): string
    {
        $existing = $this->id();

        if ($existing !== null) {
            return $existing;
        }

        $generated = CorrelationId::generate();
        $this->set($generated);

        return $generated->value;
    }

    public function forget(): void
    {
        Context::forget($this->contextKey);
    }
}
