<?php

declare(strict_types=1);

namespace App\Domain\Provisioning\Contracts;

use App\Domain\Provisioning\ConnectionResult;
use App\Domain\Provisioning\ModuleCapabilities;
use App\Domain\Provisioning\PackageChange;
use App\Domain\Provisioning\ProvisioningRequest;
use App\Domain\Provisioning\ProvisioningResult;
use App\Domain\Provisioning\ServerConnection;
use App\Domain\Provisioning\ServiceReference;
use App\Domain\Provisioning\SyncResult;

/**
 * Everything the platform knows about setting up a service.
 *
 * Four rules, each with a test, and each of them the difference between a
 * hosting system and a thing that occasionally creates accounts twice:
 *
 * 1. **An adapter never touches the database.** It takes a value object and
 *    returns one. It cannot reach a customer, an invoice or a server row,
 *    so there is no path by which a provider integration corrupts the
 *    record.
 *
 * 2. **Every call is bounded.** A timeout, limited retries with backoff,
 *    and a correlation id. A control panel that hangs must not hold a
 *    worker forever, and a request that fails must be findable in two logs
 *    at once.
 *
 * 3. **Results are three-valued.** `AlreadyDone` exists so that repeating
 *    an operation is safe: the job that times out after the account was
 *    created must be able to run again and reach the same place.
 *
 * 4. **Credentials are returned, never stored.** The adapter hands back a
 *    password in a value object; encrypting it at rest happens once, in the
 *    application layer, where the key lives.
 *
 * A module is registered only when it is configured. An operator is offered
 * what works, not what might.
 */
interface ProvisioningModule
{
    /**
     * Stable identifier, stored on products and services. Changing it
     * orphans every service that names it.
     */
    public function key(): string;

    public function capabilities(): ModuleCapabilities;

    public function testConnection(ServerConnection $server): ConnectionResult;

    public function create(ProvisioningRequest $request): ProvisioningResult;

    public function suspend(ServiceReference $service, ?string $reason = null): ProvisioningResult;

    public function unsuspend(ServiceReference $service): ProvisioningResult;

    /**
     * Destroys the account. There is no undo, which is why it has its own
     * permission and its own confirmation.
     */
    public function terminate(ServiceReference $service): ProvisioningResult;

    public function changePackage(ServiceReference $service, PackageChange $change): ProvisioningResult;

    public function sync(ServiceReference $service): SyncResult;
}
