<?php

declare(strict_types=1);

namespace App\Infrastructure\Health\Checks;

use App\Domain\Health\Contracts\HealthCheck;
use App\Domain\Health\HealthReport;
use Illuminate\Support\Facades\DB;

/**
 * Can we reach the database, and how fast.
 *
 * The measurement is a round trip in milliseconds. Not the host, not the
 * database name, not the user: "reachable in 3 ms" proves the connection
 * is configured without publishing how it is configured.
 */
final readonly class DatabaseCheck implements HealthCheck
{
    public function key(): string
    {
        return 'database';
    }

    public function run(): HealthReport
    {
        $started = microtime(true);

        DB::connection()->select('select 1');

        $milliseconds = (int) round((microtime(true) - $started) * 1000);

        if ($milliseconds > 500) {
            return HealthReport::degraded(
                $this->key(),
                (string) __('health.database.slow'),
                ['latency_ms' => $milliseconds],
            );
        }

        return HealthReport::ok($this->key(), ['latency_ms' => $milliseconds]);
    }
}
