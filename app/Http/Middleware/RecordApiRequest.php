<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Infrastructure\Api\Models\ApiRequestRecord;
use App\Support\Correlation\CorrelationContext;
use App\Support\Organizations\OrganizationContext;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Writes down that somebody asked, and what they were told.
 *
 * **Never the body.** A request body carries whatever the client sent — a
 * ticket message quoting a password, a billing address, an email — and a
 * log that keeps it becomes the most sensitive table in the installation
 * without anybody deciding that it should. What an operator actually needs
 * is who, what, when, and what came back; the correlation id leads to the
 * rest.
 *
 * The error code is lifted out of the envelope, so "what is this client
 * getting wrong" is one query rather than a scan of status codes.
 *
 * It runs outermost, so a request refused by authentication is still
 * recorded — the refused ones are the ones worth having. Nothing thrown
 * here escapes: a logging table that is full must not take the API down
 * with it.
 */
final readonly class RecordApiRequest
{
    public function __construct(
        private CorrelationContext $correlation,
        private OrganizationContext $organizations,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $request->attributes->set('api_started_at', microtime(true));

        return $next($request);
    }

    /**
     * Recorded on the way out rather than around the call.
     *
     * An authentication failure is thrown, not returned, and the exception
     * handler that turns it into a 401 sits outside every route middleware
     * — so anything wrapped around `$next` never sees the status it
     * produced. `terminate()` runs with the final response whatever
     * produced it, which is how the refused requests get recorded at all.
     */
    public function terminate(Request $request, Response $response): void
    {
        $started = $request->attributes->get('api_started_at');

        $this->record($request, $response, is_float($started) ? $started : microtime(true));
    }

    private function record(Request $request, Response $response, float $started): void
    {
        try {
            $token = $request->attributes->get('api_token');

            ApiRequestRecord::query()->create([
                'organization_id' => $this->organizations->id(),
                'token_id' => $token instanceof PersonalAccessToken ? $token->getKey() : null,
                'token_name' => $token instanceof PersonalAccessToken ? $token->name : null,
                'method' => $request->getMethod(),
                'path' => mb_substr($request->path(), 0, 191),
                'route' => $request->route()?->getName(),
                'status' => $response->getStatusCode(),
                'duration_ms' => (int) round((microtime(true) - $started) * 1000),
                'ip' => $request->ip(),
                'correlation_id' => $this->correlation->id(),
                'error_code' => $this->errorCodeOf($response),
                'created_at' => CarbonImmutable::now(),
            ]);
        } catch (Throwable) {
            // A log that cannot be written must not cost the caller their
            // answer. The correlation id is still in the response.
        }
    }

    private function errorCodeOf(Response $response): ?string
    {
        if ($response->getStatusCode() < 400) {
            return null;
        }

        $content = $response->getContent();

        if ($content === false || $content === '') {
            return null;
        }

        /** @var array{error?: array{code?: string}}|null $body */
        $body = json_decode($content, associative: true);

        return is_array($body) && isset($body['error']['code']) && is_string($body['error']['code'])
            ? $body['error']['code']
            : null;
    }
}
