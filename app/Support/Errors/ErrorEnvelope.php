<?php

declare(strict_types=1);

namespace App\Support\Errors;

use stdClass;

/**
 * The single response shape used for every non-successful JSON response.
 *
 * {
 *   "error": {
 *     "code": "validation_failed",
 *     "message": "The given data was invalid.",
 *     "details": { "email": ["The email field is required."] },
 *     "request_id": "01K5Q8ZP4F8T7M0QX9J0K3N2VB"
 *   }
 * }
 */
final readonly class ErrorEnvelope
{
    /**
     * @param  array<string, mixed>  $details
     */
    public function __construct(
        public ErrorCode $code,
        public string $message,
        public array $details = [],
        public ?string $requestId = null,
        public ?int $status = null,
    ) {}

    public function httpStatus(): int
    {
        return $this->status ?? $this->code->status();
    }

    /**
     * `details` is always encoded as a JSON object so that clients never have
     * to handle both `[]` and `{}` for the same field.
     *
     * @return array{error: array{code: string, message: string, details: array<string, mixed>|stdClass, request_id: string|null}}
     */
    public function toArray(): array
    {
        return [
            'error' => [
                'code' => $this->code->value,
                'message' => $this->message,
                'details' => $this->details === [] ? new stdClass : $this->details,
                'request_id' => $this->requestId,
            ],
        ];
    }
}
