<?php

declare(strict_types=1);

use App\Support\Errors\ErrorCode;
use App\Support\Errors\ErrorEnvelope;

it('maps every error code to a sane HTTP status', function (ErrorCode $code): void {
    expect($code->status())->toBeGreaterThanOrEqual(400)
        ->and($code->status())->toBeLessThan(600);
})->with(ErrorCode::cases());

it('round-trips the statuses it claims to own', function (): void {
    expect(ErrorCode::fromStatus(422))->toBe(ErrorCode::ValidationFailed)
        ->and(ErrorCode::fromStatus(401))->toBe(ErrorCode::Unauthenticated)
        ->and(ErrorCode::fromStatus(403))->toBe(ErrorCode::Forbidden)
        ->and(ErrorCode::fromStatus(429))->toBe(ErrorCode::RateLimited);
});

it('falls back to a server error for unknown statuses', function (): void {
    expect(ErrorCode::fromStatus(418))->toBe(ErrorCode::ServerError);
});

it('marks only transient failures retryable', function (): void {
    expect(ErrorCode::RateLimited->isRetryable())->toBeTrue()
        ->and(ErrorCode::ServiceUnavailable->isRetryable())->toBeTrue()
        ->and(ErrorCode::ExternalServiceFailure->isRetryable())->toBeTrue()
        ->and(ErrorCode::ValidationFailed->isRetryable())->toBeFalse()
        ->and(ErrorCode::Forbidden->isRetryable())->toBeFalse();
});

it('encodes empty details as an object so clients see one shape', function (): void {
    $envelope = new ErrorEnvelope(ErrorCode::Forbidden, 'Nope.');

    $json = json_encode($envelope->toArray(), JSON_THROW_ON_ERROR);

    expect($json)->toContain('"details":{}');
});

it('keeps an explicit status override', function (): void {
    $envelope = new ErrorEnvelope(ErrorCode::ValidationFailed, 'Nope.', status: 400);

    expect($envelope->httpStatus())->toBe(400);
});
