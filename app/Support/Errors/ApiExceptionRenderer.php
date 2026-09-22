<?php

declare(strict_types=1);

namespace App\Support\Errors;

use App\Support\Correlation\CorrelationContext;
use App\Support\Errors\Contracts\ProvidesErrorCode;
use App\Support\Errors\Contracts\ProvidesErrorDetails;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * Maps any throwable onto the platform error envelope.
 *
 * Internal exception messages are never leaked outside of local/testing
 * environments; clients receive a translated, user-safe sentence plus the
 * correlation identifier they can quote to support.
 */
final readonly class ApiExceptionRenderer
{
    public function __construct(
        private CorrelationContext $correlation,
        private bool $exposeInternalMessages,
    ) {}

    public function render(Throwable $e): JsonResponse
    {
        $envelope = $this->envelopeFor($e);

        $response = new JsonResponse($envelope->toArray(), $envelope->httpStatus());

        if ($e instanceof HttpExceptionInterface) {
            foreach ($e->getHeaders() as $header => $value) {
                $response->headers->set($header, $value);
            }
        }

        return $response;
    }

    public function envelopeFor(Throwable $e): ErrorEnvelope
    {
        $code = $this->codeFor($e);

        return new ErrorEnvelope(
            code: $code,
            message: $this->messageFor($e, $code),
            details: $this->detailsFor($e),
            requestId: $this->correlation->id(),
            status: $this->statusFor($e, $code),
        );
    }

    private function codeFor(Throwable $e): ErrorCode
    {
        return match (true) {
            $e instanceof ProvidesErrorCode => $e->errorCode(),
            $e instanceof ValidationException => ErrorCode::ValidationFailed,
            $e instanceof AuthenticationException => ErrorCode::Unauthenticated,
            $e instanceof AuthorizationException => ErrorCode::Forbidden,
            $e instanceof ModelNotFoundException => ErrorCode::NotFound,
            $e instanceof HttpExceptionInterface => ErrorCode::fromStatus($e->getStatusCode()),
            default => ErrorCode::ServerError,
        };
    }

    private function statusFor(Throwable $e, ErrorCode $code): int
    {
        if ($e instanceof ValidationException) {
            return $e->status;
        }

        if ($e instanceof HttpExceptionInterface && ! $e instanceof ProvidesErrorCode) {
            return $e->getStatusCode();
        }

        return $code->status();
    }

    private function messageFor(Throwable $e, ErrorCode $code): string
    {
        $default = (string) __($code->translationKey());

        // A missing translation returns the key itself; fall back to a
        // generic sentence rather than leaking "errors.server_error".
        if ($default === $code->translationKey()) {
            $default = (string) __('errors.server_error');
        }

        if ($e instanceof ProvidesErrorCode && $e->getMessage() !== '') {
            return $e->getMessage();
        }

        if ($e instanceof ValidationException) {
            return $e->getMessage();
        }

        if ($code === ErrorCode::ServerError && ! $this->exposeInternalMessages) {
            return $default;
        }

        return $e->getMessage() !== '' ? $e->getMessage() : $default;
    }

    /**
     * @return array<string, mixed>
     */
    private function detailsFor(Throwable $e): array
    {
        if ($e instanceof ValidationException) {
            return $e->errors();
        }

        if ($e instanceof ProvidesErrorDetails) {
            return $e->errorDetails();
        }

        return [];
    }
}
