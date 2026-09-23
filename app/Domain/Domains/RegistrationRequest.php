<?php

declare(strict_types=1);

namespace App\Domain\Domains;

/**
 * Everything needed to register one name.
 *
 * The registrant is passed rather than stored: the registry holds the
 * authoritative copy, and keeping a second one here would create two
 * answers to "who owns this domain".
 */
final readonly class RegistrationRequest
{
    /**
     * @param  list<string>  $nameservers
     */
    public function __construct(
        public string $domainId,
        public DomainName $name,
        public int $years,
        public RegistrantDetails $registrant,
        public array $nameservers = [],
        public bool $whoisPrivacy = false,
        public bool $autoRenew = true,
        public ?string $correlationId = null,
    ) {}
}
