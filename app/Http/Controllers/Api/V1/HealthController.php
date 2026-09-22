<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Support\Correlation\CorrelationContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Throwable;

/**
 * Liveness and dependency health.
 *
 * Returns 503 when a hard dependency is down so that a load balancer takes
 * the instance out of rotation instead of serving errors to customers.
 */
final class HealthController extends Controller
{
    public function __invoke(CorrelationContext $correlation): JsonResponse
    {
        $checks = [
            'database' => $this->check(static fn () => DB::connection()->getPdo()),
            'cache' => $this->check(static fn () => cache()->set('health:probe', '1', 5)),
            'redis' => $this->check(static fn () => Redis::connection()->command('ping', [])),
        ];

        $healthy = ! in_array(false, array_column($checks, 'healthy'), strict: true);

        return new JsonResponse([
            'status' => $healthy ? 'ok' : 'degraded',
            'checks' => $checks,
            'request_id' => $correlation->idOrGenerate(),
            'time' => now()->toIso8601String(),
        ], $healthy ? 200 : 503);
    }

    /**
     * @param  callable(): mixed  $probe
     * @return array{healthy: bool, error: string|null}
     */
    private function check(callable $probe): array
    {
        try {
            $probe();

            return ['healthy' => true, 'error' => null];
        } catch (Throwable $e) {
            report($e);

            return ['healthy' => false, 'error' => class_basename($e)];
        }
    }
}
