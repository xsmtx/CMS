<?php

declare(strict_types=1);

namespace App\Providers;

use App\Support\Errors\ErrorCode;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * The public API's rate limits, and the shape a 429 comes back in.
 *
 * **Per token, not per IP**, once a token is known. Two integrations behind
 * one office NAT are two clients, and limiting them together means the
 * noisy one silently starves the careful one. The IP is the fallback for
 * requests that never got as far as a token, which is also where an
 * unauthenticated flood arrives.
 *
 * **Writes are limited harder than reads.** A runaway read loop is a
 * nuisance; a runaway write loop opens four hundred tickets.
 *
 * The refusal goes through the same envelope as every other error, with
 * `Retry-After`, because a client that has to parse a different shape for
 * one status code will not parse it at all.
 */
final class ApiServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        RateLimiter::for('api', function (Request $request): Limit {
            $token = $request->attributes->get('api_token');

            if (! $token instanceof PersonalAccessToken) {
                return Limit::perMinute($this->limit('anonymous_per_minute', 20))
                    ->by('api:ip:'.$request->ip())
                    ->response($this->refusal(...));
            }

            $writing = ! $request->isMethodSafe();

            return Limit::perMinute(
                $writing
                    ? $this->limit('writes_per_minute', 30)
                    : $this->limit('per_minute', 120),
            )
                ->by('api:token:'.$token->getKey().':'.($writing ? 'w' : 'r'))
                ->response($this->refusal(...));
        });
    }

    /**
     * @param  array<string, mixed>  $headers
     */
    private function refusal(Request $request, array $headers = []): JsonResponse
    {
        $retryAfter = $headers['Retry-After'] ?? null;

        return new JsonResponse([
            'error' => [
                'code' => ErrorCode::RateLimited->value,
                'message' => (string) __('api.errors.rate_limited'),
                'details' => (object) [],
                'request_id' => $request->header('X-Correlation-Id'),
            ],
        ], ErrorCode::RateLimited->status(), array_filter([
            'Retry-After' => $retryAfter === null ? null : (string) $retryAfter,
        ]));
    }

    private function limit(string $key, int $default): int
    {
        return (int) config('platform.api.rate_limit.'.$key, $default);
    }
}
