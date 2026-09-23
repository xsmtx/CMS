<?php

declare(strict_types=1);

namespace App\Domain\Domains;

use App\Domain\Provisioning\OperationOutcome;
use SensitiveParameter;

/**
 * What a registrar answered.
 *
 * Shares `OperationOutcome` with provisioning deliberately: the three-valued
 * answer is the same idea and inventing a second enum would mean two
 * definitions of what "already done" means.
 *
 * `transferCode` is returned here and never written to the database.
 */
final readonly class RegistrarResult
{
    /**
     * @param  list<string>  $nameservers
     * @param  array<string, mixed>  $metadata  Sanitised. Never a secret.
     */
    public function __construct(
        public OperationOutcome $outcome,
        public ?string $externalId = null,
        public ?string $expiresOn = null,
        public array $nameservers = [],
        #[SensitiveParameter]
        public ?string $transferCode = null,
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
            'expiresOn' => $this->expiresOn,
            'transferCode' => $this->transferCode === null ? null : '[redacted]',
            'message' => $this->message,
        ];
    }

    /**
     * @param  list<string>  $nameservers
     * @param  array<string, mixed>  $metadata
     */
    public static function succeeded(
        ?string $externalId = null,
        ?string $expiresOn = null,
        array $nameservers = [],
        array $metadata = [],
    ): self {
        return new self(
            OperationOutcome::Succeeded,
            $externalId,
            $expiresOn,
            $nameservers,
            metadata: $metadata,
        );
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

    public static function code(#[SensitiveParameter] string $transferCode): self
    {
        return new self(OperationOutcome::Succeeded, transferCode: $transferCode);
    }

    public function isSuccessful(): bool
    {
        return $this->outcome->isSuccessful();
    }
}
