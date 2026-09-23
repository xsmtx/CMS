<?php

declare(strict_types=1);

namespace App\Infrastructure\Modules;

use App\Domain\Modules\Contracts\ModuleLogger;
use Illuminate\Support\Facades\Log;

/**
 * A module's own log lines, with its slug on every one.
 *
 * Isolated by context rather than by file: this installation already sends
 * everything through one structured channel with a correlation id and a
 * secret redactor on it, and a module writing to a file of its own would
 * escape both. The slug in the context is what makes "what did that module
 * do" answerable.
 *
 * The level is capped at `error`. A module cannot raise an emergency, page
 * anybody, or write at a level that an operator's alerting treats as the
 * platform failing — it is not the platform.
 */
final readonly class ChannelModuleLogger implements ModuleLogger
{
    public function __construct(private string $slug) {}

    public function info(string $message, array $context = []): void
    {
        Log::info($this->prefix($message), $this->context($context));
    }

    public function warning(string $message, array $context = []): void
    {
        Log::warning($this->prefix($message), $this->context($context));
    }

    public function error(string $message, array $context = []): void
    {
        Log::error($this->prefix($message), $this->context($context));
    }

    private function prefix(string $message): string
    {
        return '[module:'.$this->slug.'] '.$message;
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function context(array $context): array
    {
        // The module's own key wins nothing: a module that logged
        // `['module' => 'billing']` must not be able to make its lines look
        // like somebody else's.
        return [...$context, 'module' => $this->slug];
    }
}
