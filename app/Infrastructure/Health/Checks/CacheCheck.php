<?php

declare(strict_types=1);

namespace App\Infrastructure\Health\Checks;

use App\Domain\Health\Contracts\HealthCheck;
use App\Domain\Health\HealthReport;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Can we write to the cache and read it back.
 *
 * A round trip rather than a ping: a cache that accepts connections and
 * silently discards writes — a full Redis with `noeviction`, a
 * misconfigured driver — passes a ping and fails the only thing it is for.
 */
final readonly class CacheCheck implements HealthCheck
{
    public function key(): string
    {
        return 'cache';
    }

    public function run(): HealthReport
    {
        $key = 'health:'.Str::random(12);
        $started = microtime(true);

        Cache::put($key, 'ok', 10);
        $read = Cache::get($key);
        Cache::forget($key);

        $milliseconds = (int) round((microtime(true) - $started) * 1000);

        if ($read !== 'ok') {
            return HealthReport::failing($this->key(), (string) __('health.cache.not_readable'));
        }

        return HealthReport::ok($this->key(), ['latency_ms' => $milliseconds]);
    }
}
