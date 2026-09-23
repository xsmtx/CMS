<?php

declare(strict_types=1);

namespace App\Domain\Domains;

use SensitiveParameter;

/**
 * Moving a name in from another registrar.
 *
 * The authorisation code is carried through and never stored: it is the
 * credential that moves a domain, and a copy of it sitting in this database
 * is a copy nobody needs.
 */
final readonly class TransferRequest
{
    public function __construct(
        public string $domainId,
        public DomainName $name,
        #[SensitiveParameter]
        public string $authCode,
        public RegistrantDetails $registrant,
        public int $years = 1,
        public ?string $correlationId = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return [
            'domainId' => $this->domainId,
            'name' => (string) $this->name,
            'authCode' => '[redacted]',
            'years' => $this->years,
        ];
    }
}
