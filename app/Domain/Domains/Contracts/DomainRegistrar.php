<?php

declare(strict_types=1);

namespace App\Domain\Domains\Contracts;

use App\Domain\Domains\AvailabilityResult;
use App\Domain\Domains\DomainName;
use App\Domain\Domains\DomainReference;
use App\Domain\Domains\DomainSyncResult;
use App\Domain\Domains\RegistrarCapabilities;
use App\Domain\Domains\RegistrarResult;
use App\Domain\Domains\RegistrationRequest;
use App\Domain\Domains\TransferRequest;

/**
 * Everything the platform knows about registering a name.
 *
 * The four rules from
 * [ADR 0026](../../../../docs/adr/0026-provisioning-is-idempotent-and-failure-is-a-state.md)
 * carry over unchanged — an adapter never touches the database, every call
 * is bounded, results are three-valued, and credentials are returned rather
 * than stored — and one is added:
 *
 * **An EPP transfer code is never stored.** It is fetched when a customer
 * asks for it and shown once. It is the credential that moves a domain
 * away from this platform; a copy of it sitting in this database is a copy
 * nobody needs and everybody would have to protect.
 *
 * One more thing separates this from the provisioning contract. A registry
 * that does not answer has **not** said a name is available. `Unknown` is a
 * first-class answer, and an adapter that reports it as "available" is how
 * a customer gets sold a domain that already belongs to somebody else.
 */
interface DomainRegistrar
{
    /**
     * Stable identifier, stored on TLDs and domains. Changing it orphans
     * every domain that names it.
     */
    public function key(): string;

    public function capabilities(): RegistrarCapabilities;

    public function checkAvailability(DomainName $name): AvailabilityResult;

    public function register(RegistrationRequest $request): RegistrarResult;

    public function transfer(TransferRequest $request): RegistrarResult;

    public function renew(DomainReference $domain, int $years): RegistrarResult;

    /**
     * @param  list<string>  $nameservers
     */
    public function setNameservers(DomainReference $domain, array $nameservers): RegistrarResult;

    public function setLock(DomainReference $domain, bool $locked): RegistrarResult;

    public function setAutoRenew(DomainReference $domain, bool $enabled): RegistrarResult;

    /**
     * Returns the code in the result. It is never persisted.
     */
    public function requestTransferCode(DomainReference $domain): RegistrarResult;

    public function sync(DomainReference $domain): DomainSyncResult;
}
