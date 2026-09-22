<?php

declare(strict_types=1);

namespace App\Support\Errors;

use App\Support\Errors\Contracts\ProvidesErrorCode;
use App\Support\Errors\Contracts\ProvidesErrorDetails;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * Base class for exceptions that are safe to surface to a caller.
 *
 * Domain and application code should extend this (or implement the two
 * contracts directly) so that failures arrive at the client with a stable
 * error code instead of a generic 500.
 */
abstract class PlatformException extends RuntimeException implements HttpExceptionInterface, ProvidesErrorCode, ProvidesErrorDetails
{
    /**
     * @param  array<string, mixed>  $details
     */
    public function __construct(
        string $message = '',
        private readonly array $details = [],
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    abstract public function errorCode(): ErrorCode;

    /**
     * @return array<string, mixed>
     */
    public function errorDetails(): array
    {
        return $this->details;
    }

    /**
     * Translation key used when the exception carries no explicit message.
     */
    public function translationKey(): string
    {
        return $this->errorCode()->translationKey();
    }

    /**
     * The status an HTML response uses.
     *
     * Without this a domain refusal renders as a 500 on the browser
     * surfaces while the API returns the right code, which is exactly the
     * kind of divergence the shared error contract exists to prevent.
     */
    public function getStatusCode(): int
    {
        return $this->errorCode()->status();
    }

    /**
     * @return array<string, string>
     */
    public function getHeaders(): array
    {
        return [];
    }
}
