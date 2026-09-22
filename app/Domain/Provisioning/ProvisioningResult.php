<?php

declare(strict_types=1);

namespace App\Domain\Provisioning;

use SensitiveParameter;

/**
 * What an adapter answered.
 *
 * Three outcomes, not two: `AlreadyDone` is what makes a retried job safe.
 * An adapter that reports "this account already exists" as a failure turns
 * every retry into a permanent one.
 *
 * Credentials come back here rather than being written by the adapter.
 * Encrypting them at rest is the caller's job, done once, in one place.
 */
final readonly class ProvisioningResult
{
    /**
     * @param  array<string, mixed>  $metadata  Sanitised. Never a secret.
     */
    public function __construct(
        public OperationOutcome $outcome,
        public ?string $externalId = null,
        public ?string $username = null,
        #[SensitiveParameter]
        public ?string $password = null,
        public ?string $message = null,
        public array $metadata = [],
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return [
            'outcome' => $this->outcome->value,
            'externalId' => $this->externalId,
            'username' => $this->username,
            'password' => $this->password === null ? null : '[redacted]',
            'message' => $this->message,
        ];
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    public static function succeeded(
        ?string $externalId = null,
        ?string $username = null,
        #[SensitiveParameter] ?string $password = null,
        array $metadata = [],
    ): self {
        return new self(OperationOutcome::Succeeded, $externalId, $username, $password, null, $metadata);
    }

    public static function alreadyDone(?string $externalId = null, ?string $message = null): self
    {
        return new self(OperationOutcome::AlreadyDone, $externalId, message: $message);
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    public static function failed(string $message, array $metadata = []): self
    {
        return new self(OperationOutcome::Failed, message: $message, metadata: $metadata);
    }

    public function isSuccessful(): bool
    {
        return $this->outcome->isSuccessful();
    }
}
