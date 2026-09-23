<?php

declare(strict_types=1);

namespace App\Domain\Domains;

/**
 * What a registrar can actually do.
 *
 * Asked rather than assumed, so a customer is offered a transfer only where
 * transferring means something and core never calls a method an adapter
 * cannot honour.
 */
final readonly class RegistrarCapabilities
{
    public function __construct(
        public bool $checkAvailability = false,
        public bool $register = true,
        public bool $transfer = false,
        public bool $renew = false,
        public bool $nameservers = false,
        public bool $lock = false,
        public bool $autoRenew = false,
        public bool $transferCode = false,
        public bool $sync = false,
        public bool $whoisPrivacy = false,
    ) {}

    public function supports(DomainOperation $operation): bool
    {
        return match ($operation) {
            DomainOperation::CheckAvailability => $this->checkAvailability,
            DomainOperation::Register => $this->register,
            DomainOperation::Transfer => $this->transfer,
            DomainOperation::Renew => $this->renew,
            DomainOperation::SetNameservers => $this->nameservers,
            DomainOperation::SetLock => $this->lock,
            DomainOperation::SetAutoRenew => $this->autoRenew,
            DomainOperation::RequestTransferCode => $this->transferCode,
            DomainOperation::Sync => $this->sync,
        };
    }
}
