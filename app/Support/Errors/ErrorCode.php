<?php

declare(strict_types=1);

namespace App\Support\Errors;

/**
 * Stable, machine-readable error identifiers.
 *
 * API clients switch on these values. A member may be added at any time, but
 * an existing value must never be renamed or repurposed: doing so is a
 * breaking API change and requires a new API version.
 */
enum ErrorCode: string
{
    case ValidationFailed = 'validation_failed';
    case Unauthenticated = 'unauthenticated';
    case Forbidden = 'forbidden';
    case NotFound = 'not_found';
    case MethodNotAllowed = 'method_not_allowed';
    case Conflict = 'conflict';
    case InvalidStateTransition = 'invalid_state_transition';
    case PreconditionFailed = 'precondition_failed';
    case IdempotencyKeyConflict = 'idempotency_key_conflict';
    case PayloadTooLarge = 'payload_too_large';
    case UnsupportedMediaType = 'unsupported_media_type';
    case RateLimited = 'rate_limited';
    case ExternalServiceFailure = 'external_service_failure';
    case ServiceUnavailable = 'service_unavailable';
    case ServerError = 'server_error';

    /**
     * The HTTP status this code is rendered with when the thrower does not
     * override it.
     */
    public function status(): int
    {
        return match ($this) {
            self::ValidationFailed => 422,
            self::Unauthenticated => 401,
            self::Forbidden => 403,
            self::NotFound => 404,
            self::MethodNotAllowed => 405,
            self::Conflict, self::InvalidStateTransition, self::IdempotencyKeyConflict => 409,
            self::PreconditionFailed => 412,
            self::PayloadTooLarge => 413,
            self::UnsupportedMediaType => 415,
            self::RateLimited => 429,
            self::ExternalServiceFailure => 502,
            self::ServiceUnavailable => 503,
            self::ServerError => 500,
        };
    }

    /**
     * Translation key for the default, user-safe message.
     */
    public function translationKey(): string
    {
        return 'errors.'.$this->value;
    }

    /**
     * Whether a client may reasonably retry the same request unchanged.
     */
    public function isRetryable(): bool
    {
        return match ($this) {
            self::RateLimited, self::ExternalServiceFailure, self::ServiceUnavailable => true,
            default => false,
        };
    }

    public static function fromStatus(int $status): self
    {
        return match ($status) {
            401 => self::Unauthenticated,
            403 => self::Forbidden,
            404 => self::NotFound,
            405 => self::MethodNotAllowed,
            409 => self::Conflict,
            412 => self::PreconditionFailed,
            413 => self::PayloadTooLarge,
            415 => self::UnsupportedMediaType,
            422 => self::ValidationFailed,
            429 => self::RateLimited,
            502 => self::ExternalServiceFailure,
            503 => self::ServiceUnavailable,
            default => self::ServerError,
        };
    }
}
