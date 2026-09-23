<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Infrastructure\Api\Models\IdempotencyRecord;
use App\Support\Errors\IdempotencyConflictException;
use App\Support\Organizations\OrganizationContext;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Makes a write happen once, however many times it is asked for.
 *
 * The problem is not duplicate requests; it is a client that **did not hear
 * the answer**. A POST that creates an order times out at a proxy and the
 * client has no idea whether the order exists. Retrying risks two; not
 * retrying risks none. An idempotency key is the only way out, and the
 * half-version — refusing the retry — leaves the client exactly where it
 * started.
 *
 * So the stored response is replayed **verbatim**, status code included.
 * The client's second attempt gets the answer its first attempt produced,
 * which is the only correct thing to say.
 *
 * Three cases, and the middle one is the one that earns the table:
 *
 * - **Same key, same request** → the stored response, or a conflict while
 *   the first is still running. Two identical requests in flight together
 *   is a retry that arrived too fast, not a second intention.
 * - **Same key, different request** → `idempotency_key_conflict`. The
 *   client has a bug — a key reused across two different writes — and
 *   quietly returning the first answer would hide it behind a response
 *   that looks correct.
 * - **No key** → the write proceeds. The header is offered, not demanded:
 *   requiring it would break every client that sends a single request and
 *   reads the answer, which is most of them.
 *
 * Keys are scoped to the token, never to the installation. Two integrations
 * generating UUIDs must not be able to collide, and a key is only ever a
 * promise to the client that sent it.
 */
final readonly class EnforceIdempotency
{
    public function __construct(private OrganizationContext $organizations) {}

    public function handle(Request $request, Closure $next): Response
    {
        $key = $request->header('Idempotency-Key');

        if (! is_string($key) || trim($key) === '') {
            return $next($request);
        }

        $key = trim($key);
        $token = $request->attributes->get('api_token');
        $tokenId = $token instanceof PersonalAccessToken ? (string) $token->getKey() : null;
        $fingerprint = $this->fingerprint($request);

        $existing = IdempotencyRecord::query()
            ->where('token_id', $tokenId)
            ->where('key', $key)
            ->first();

        if ($existing instanceof IdempotencyRecord) {
            return $this->replay($existing, $fingerprint);
        }

        $record = $this->claim($key, $tokenId, $fingerprint);

        if (! $record instanceof IdempotencyRecord) {
            // Somebody else claimed the key between the read and the write.
            // Whatever they are doing with it, this request is not it.
            throw $this->conflict();
        }

        try {
            $response = $next($request);
        } catch (Throwable $exception) {
            // A failed attempt must not burn the key: the client is
            // entitled to retry the same write and get a real answer.
            $record->delete();

            throw $exception;
        }

        $this->remember($record, $response);

        return $response;
    }

    private function replay(IdempotencyRecord $record, string $fingerprint): JsonResponse
    {
        if (! hash_equals($record->fingerprint, $fingerprint)) {
            throw $this->conflict();
        }

        if (! $record->isComplete()) {
            // The first request is still in flight. Returning a made-up
            // success would be worse than saying "ask again".
            throw $this->conflict((string) __('api.errors.idempotency_in_flight'));
        }

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $record->response, associative: true) ?? [];

        return new JsonResponse($body, (int) $record->status, [
            // So a client can tell a replay from a fresh write, which is
            // the difference between "I created two" and "I created one".
            'Idempotent-Replay' => 'true',
        ]);
    }

    private function claim(string $key, ?string $tokenId, string $fingerprint): ?IdempotencyRecord
    {
        $organizationId = $this->organizations->id();

        if ($organizationId === null) {
            return null;
        }

        try {
            return IdempotencyRecord::query()->create([
                'organization_id' => $organizationId,
                'token_id' => $tokenId,
                'key' => $key,
                'fingerprint' => $fingerprint,
                'created_at' => CarbonImmutable::now(),
            ]);
        } catch (Throwable) {
            // The unique index did its job. Two requests raced and this one
            // lost, which is exactly the outcome the index exists for.
            return null;
        }
    }

    private function remember(IdempotencyRecord $record, Response $response): void
    {
        $status = $response->getStatusCode();

        // **Only a success is worth remembering.** A key exists to stop a
        // write happening twice, and a request that was refused did not
        // write anything: holding the key would mean a client that fixed
        // its payload could never retry, because the corrected body would
        // conflict with the fingerprint of the one that failed. Releasing
        // it is what makes "send it again with the same key" the right
        // advice in every case.
        //
        // This is also why the rule is the status rather than whether an
        // exception escaped: Laravel's routing pipeline turns a validation
        // failure into a 422 *response* before this middleware sees it, so
        // a try/catch here would never fire for the commonest refusal.
        if ($status < 200 || $status >= 300) {
            $record->delete();

            return;
        }

        $record->forceFill([
            'status' => $status,
            'response' => $response->getContent() === false ? null : $response->getContent(),
            'completed_at' => CarbonImmutable::now(),
        ])->save();
    }

    /**
     * Method, path and body. Two requests that differ anywhere differ here.
     */
    private function fingerprint(Request $request): string
    {
        return hash('sha256', implode("\n", [
            $request->getMethod(),
            $request->path(),
            $request->getContent(),
        ]));
    }

    private function conflict(?string $message = null): IdempotencyConflictException
    {
        return new IdempotencyConflictException(
            $message ?? (string) __('api.errors.idempotency_conflict'),
        );
    }
}
