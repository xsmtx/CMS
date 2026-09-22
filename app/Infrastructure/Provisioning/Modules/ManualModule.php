<?php

declare(strict_types=1);

namespace App\Infrastructure\Provisioning\Modules;

use App\Domain\Provisioning\ConnectionResult;
use App\Domain\Provisioning\Contracts\ProvisioningModule;
use App\Domain\Provisioning\ModuleCapabilities;
use App\Domain\Provisioning\PackageChange;
use App\Domain\Provisioning\ProvisioningRequest;
use App\Domain\Provisioning\ProvisioningResult;
use App\Domain\Provisioning\ServerConnection;
use App\Domain\Provisioning\ServiceReference;
use App\Domain\Provisioning\SyncResult;

/**
 * An operator does it by hand.
 *
 * A real module rather than a special case, for the same reason the manual
 * payment gateway is one: "somebody set this up themselves" and "cPanel set
 * this up" then travel the same path, produce the same events and appear
 * the same way on a service. There is no second code path for the case that
 * turns out to be the most common one in a young installation.
 *
 * Every operation succeeds immediately and does nothing, because the work
 * happens outside this system. What it leaves behind is the event record
 * saying an operator asserted it.
 */
final class ManualModule implements ProvisioningModule
{
    public function key(): string
    {
        return 'manual';
    }

    public function capabilities(): ModuleCapabilities
    {
        return new ModuleCapabilities(
            create: true,
            suspend: true,
            unsuspend: true,
            terminate: true,
            changePackage: true,
            sync: false,
            testConnection: false,
            issuesCredentials: false,
            // No node is involved. A manual product is not placed.
            needsServer: false,
        );
    }

    public function testConnection(ServerConnection $server): ConnectionResult
    {
        return ConnectionResult::failed(__('provisioning.manual.no_connection'));
    }

    public function create(ProvisioningRequest $request): ProvisioningResult
    {
        return ProvisioningResult::succeeded();
    }

    public function suspend(ServiceReference $service, ?string $reason = null): ProvisioningResult
    {
        return ProvisioningResult::succeeded();
    }

    public function unsuspend(ServiceReference $service): ProvisioningResult
    {
        return ProvisioningResult::succeeded();
    }

    public function terminate(ServiceReference $service): ProvisioningResult
    {
        return ProvisioningResult::succeeded();
    }

    public function changePackage(ServiceReference $service, PackageChange $change): ProvisioningResult
    {
        return ProvisioningResult::succeeded();
    }

    /**
     * Nothing to ask. A sync that invented an answer would be worse than
     * one that admits it cannot look.
     */
    public function sync(ServiceReference $service): SyncResult
    {
        return new SyncResult(
            reachable: false,
            message: (string) __('provisioning.manual.no_sync'),
        );
    }
}
