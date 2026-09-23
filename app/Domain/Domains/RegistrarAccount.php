<?php

declare(strict_types=1);

namespace App\Domain\Domains;

use SensitiveParameter;

/**
 * The credentials for one registrar account.
 *
 * A value object rather than a configuration array, so an adapter is handed
 * exactly what it needs and nothing else, and so the secret can refuse to
 * print itself.
 */
final readonly class RegistrarAccount
{
    public function __construct(
        public string $username,
        #[SensitiveParameter]
        public string $apiKey,
        public ?string $clientIp = null,
        public bool $sandbox = false,
        public ?string $apiBase = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return [
            'username' => $this->username,
            'apiKey' => '[redacted]',
            'sandbox' => $this->sandbox,
        ];
    }
}
