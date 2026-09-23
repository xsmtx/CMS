<?php

declare(strict_types=1);

namespace App\Domain\Modules\Contracts;

/**
 * Where a module writes.
 *
 * A platform-owned interface rather than the framework's, for the reason
 * the whole SDK exists: a module that imported the application's logger
 * would be coupled to how this application happens to log today, and
 * changing that would be a breaking change for code somebody else wrote.
 *
 * Every line carries the module's slug, so "what did that module do" is
 * answerable without reading everything else — and a module cannot write
 * as though it were core.
 */
interface ModuleLogger
{
    /**
     * @param  array<string, mixed>  $context
     */
    public function info(string $message, array $context = []): void;

    /**
     * @param  array<string, mixed>  $context
     */
    public function warning(string $message, array $context = []): void;

    /**
     * @param  array<string, mixed>  $context
     */
    public function error(string $message, array $context = []): void;
}
