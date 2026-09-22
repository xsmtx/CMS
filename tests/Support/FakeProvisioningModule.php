<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Provisioning\ConnectionResult;
use App\Domain\Provisioning\Contracts\ProvisioningModule;
use App\Domain\Provisioning\ModuleCapabilities;
use App\Domain\Provisioning\PackageChange;
use App\Domain\Provisioning\ProvisioningRequest;
use App\Domain\Provisioning\ProvisioningResult;
use App\Domain\Provisioning\ServerConnection;
use App\Domain\Provisioning\ServiceReference;
use App\Domain\Provisioning\ServiceStatus;
use App\Domain\Provisioning\SyncResult;
use RuntimeException;

/**
 * A provider that does what the test tells it to.
 *
 * Counts its calls, so "did this run twice" is an assertion rather than an
 * inference, and can be told to fail or to report that the work was already
 * done — the two behaviours that decide whether retrying is safe.
 */
final class FakeProvisioningModule implements ProvisioningModule
{
    /** @var array<string, int> */
    public array $calls = [];

    /** @var list<ProvisioningRequest> */
    public array $requests = [];

    public function __construct(
        private readonly string $key = 'fake',
        private readonly bool $needsServer = true,
        private ?ProvisioningResult $nextResult = null,
        private readonly ?string $throwMessage = null,
    ) {}

    public function willReturn(ProvisioningResult $result): void
    {
        $this->nextResult = $result;
    }

    public function key(): string
    {
        return $this->key;
    }

    public function capabilities(): ModuleCapabilities
    {
        return new ModuleCapabilities(
            create: true,
            suspend: true,
            unsuspend: true,
            terminate: true,
            changePackage: true,
            sync: true,
            testConnection: true,
            issuesCredentials: true,
            needsServer: $this->needsServer,
        );
    }

    public function testConnection(ServerConnection $server): ConnectionResult
    {
        $this->record('test_connection');

        return ConnectionResult::ok('fake 1.0', 12);
    }

    public function create(ProvisioningRequest $request): ProvisioningResult
    {
        $this->record('create');
        $this->requests[] = $request;

        return $this->answer(fn (): ProvisioningResult => ProvisioningResult::succeeded(
            externalId: 'acct_'.substr($request->serviceId, -6),
            username: 'user'.substr($request->serviceId, -4),
            password: 'issued-by-the-provider',
        ));
    }

    public function suspend(ServiceReference $service, ?string $reason = null): ProvisioningResult
    {
        $this->record('suspend');

        return $this->answer(fn (): ProvisioningResult => ProvisioningResult::succeeded());
    }

    public function unsuspend(ServiceReference $service): ProvisioningResult
    {
        $this->record('unsuspend');

        return $this->answer(fn (): ProvisioningResult => ProvisioningResult::succeeded());
    }

    public function terminate(ServiceReference $service): ProvisioningResult
    {
        $this->record('terminate');

        return $this->answer(fn (): ProvisioningResult => ProvisioningResult::succeeded());
    }

    public function changePackage(ServiceReference $service, PackageChange $change): ProvisioningResult
    {
        $this->record('change_package');

        return $this->answer(fn (): ProvisioningResult => ProvisioningResult::succeeded());
    }

    public function sync(ServiceReference $service): SyncResult
    {
        $this->record('sync');

        if ($this->throwMessage !== null) {
            throw new RuntimeException($this->throwMessage);
        }

        return new SyncResult(true, ServiceStatus::Active, ['disk_used' => '120M']);
    }

    public function callsTo(string $operation): int
    {
        return $this->calls[$operation] ?? 0;
    }

    /**
     * @param  callable(): ProvisioningResult  $default
     */
    private function answer(callable $default): ProvisioningResult
    {
        if ($this->throwMessage !== null) {
            throw new RuntimeException($this->throwMessage);
        }

        return $this->nextResult ?? $default();
    }

    private function record(string $operation): void
    {
        $this->calls[$operation] = ($this->calls[$operation] ?? 0) + 1;
    }
}
