<?php

declare(strict_types=1);

namespace App\Domain\Domains;

/**
 * What the registry says is true right now.
 *
 * Reports; does not decide. Whether a domain the registry calls expired
 * should be marked expired here, and what that should cost the customer, is
 * a question for the application layer and for Phase 9's automation.
 */
final readonly class DomainSyncResult
{
    /**
     * @param  list<string>  $nameservers
     */
    public function __construct(
        public bool $reachable,
        public ?DomainStatus $remoteStatus = null,
        public ?string $expiresOn = null,
        public array $nameservers = [],
        public ?bool $locked = null,
        public ?bool $autoRenew = null,
        public ?string $message = null,
    ) {}

    public static function unreachable(string $message): self
    {
        return new self(false, message: $message);
    }
}
