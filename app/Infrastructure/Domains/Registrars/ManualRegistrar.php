<?php

declare(strict_types=1);

namespace App\Infrastructure\Domains\Registrars;

use App\Domain\Domains\AvailabilityResult;
use App\Domain\Domains\Contracts\DomainRegistrar;
use App\Domain\Domains\DomainName;
use App\Domain\Domains\DomainReference;
use App\Domain\Domains\DomainSyncResult;
use App\Domain\Domains\RegistrarCapabilities;
use App\Domain\Domains\RegistrarResult;
use App\Domain\Domains\RegistrationRequest;
use App\Domain\Domains\TransferRequest;

/**
 * An operator registers it at the registrar's own panel.
 *
 * A real registrar rather than a special case, for the same reason the
 * manual gateway and the manual provisioning module are: "somebody did this
 * by hand" then travels the same path, produces the same events and appears
 * the same way on a domain.
 *
 * It cannot check availability, and says so rather than guessing.
 * Answering "available" without asking anybody is how a customer gets sold
 * a name that already belongs to a law firm.
 */
final class ManualRegistrar implements DomainRegistrar
{
    public function key(): string
    {
        return 'manual';
    }

    public function capabilities(): RegistrarCapabilities
    {
        return new RegistrarCapabilities(
            checkAvailability: false,
            register: true,
            transfer: true,
            renew: true,
            nameservers: true,
            lock: true,
            autoRenew: true,
            transferCode: false,
            sync: false,
            whoisPrivacy: false,
        );
    }

    public function checkAvailability(DomainName $name): AvailabilityResult
    {
        return AvailabilityResult::unknown($name, (string) __('domains.manual.no_availability'));
    }

    public function register(RegistrationRequest $request): RegistrarResult
    {
        return RegistrarResult::succeeded();
    }

    public function transfer(TransferRequest $request): RegistrarResult
    {
        return RegistrarResult::succeeded();
    }

    public function renew(DomainReference $domain, int $years): RegistrarResult
    {
        return RegistrarResult::succeeded();
    }

    public function setNameservers(DomainReference $domain, array $nameservers): RegistrarResult
    {
        return RegistrarResult::succeeded(nameservers: $nameservers);
    }

    public function setLock(DomainReference $domain, bool $locked): RegistrarResult
    {
        return RegistrarResult::succeeded();
    }

    public function setAutoRenew(DomainReference $domain, bool $enabled): RegistrarResult
    {
        return RegistrarResult::succeeded();
    }

    public function requestTransferCode(DomainReference $domain): RegistrarResult
    {
        return RegistrarResult::failed((string) __('domains.manual.no_transfer_code'));
    }

    public function sync(DomainReference $domain): DomainSyncResult
    {
        return DomainSyncResult::unreachable((string) __('domains.manual.no_sync'));
    }
}
