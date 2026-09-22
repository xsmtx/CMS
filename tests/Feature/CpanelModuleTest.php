<?php

declare(strict_types=1);

use App\Domain\Provisioning\OperationOutcome;
use App\Domain\Provisioning\PackageChange;
use App\Domain\Provisioning\ProvisioningRequest;
use App\Domain\Provisioning\ServerConnection;
use App\Domain\Provisioning\ServiceReference;
use App\Domain\Provisioning\ServiceStatus;
use App\Infrastructure\Provisioning\Modules\CpanelModule;
use Illuminate\Support\Facades\Http;

/**
 * Tested against faked HTTP, which proves the code and not the
 * integration. It has never talked to a real WHM.
 */
beforeEach(function (): void {
    $this->module = new CpanelModule(timeout: 5, retries: 0);

    $this->server = new ServerConnection(
        id: 'srv_1',
        hostname: 'whm.test',
        ipAddress: '203.0.113.10',
        port: 2087,
        username: 'root',
        secret: 'whm-token-value',
    );

    $this->reference = new ServiceReference(
        id: 'svc_1',
        server: $this->server,
        externalId: 'bobhost',
        username: 'bobhost',
        domain: 'bob.test',
        package: 'starter',
    );
});

function whm(array $payload, int $status = 200): void
{
    Http::fake(['whm.test:2087/*' => Http::response($payload, $status)]);
}

it('creates an account and hands back the credentials it issued', function (): void {
    whm(['metadata' => ['result' => 1, 'reason' => 'Account Created']]);

    $result = $this->module->create(new ProvisioningRequest(
        serviceId: 'svc_1',
        server: $this->server,
        package: 'starter',
        domain: 'bob.test',
        username: null,
        email: 'bob@example.test',
    ));

    expect($result->outcome)->toBe(OperationOutcome::Succeeded)
        ->and($result->externalId)->toBe('bob')
        ->and($result->password)->not->toBeNull();

    Http::assertSent(fn ($request): bool => str_contains($request->url(), 'createacct')
        && $request['domain'] === 'bob.test'
        && $request['plan'] === 'starter');
});

it('authenticates with a token, never a password', function (): void {
    whm(['metadata' => ['result' => 1]]);

    $this->module->create(new ProvisioningRequest(
        serviceId: 'svc_1',
        server: $this->server,
        package: 'starter',
        domain: 'bob.test',
        username: null,
        email: null,
    ));

    Http::assertSent(fn ($request): bool => $request->hasHeader(
        'Authorization',
        'whm root:whm-token-value',
    ));
});

it('derives the same username twice, so a retry asks for the same account', function (): void {
    whm(['metadata' => ['result' => 1]]);

    $request = new ProvisioningRequest(
        serviceId: 'svc_1',
        server: $this->server,
        package: 'starter',
        domain: 'bob.test',
        username: null,
        email: null,
    );

    $first = $this->module->create($request);
    $second = $this->module->create($request);

    expect($second->externalId)->toBe($first->externalId);
});

it('reports an account that already exists as already done', function (): void {
    // The job timed out after WHM had created it. A retry must reach the
    // same place rather than failing forever.
    whm(['metadata' => ['result' => 0, 'reason' => 'Sorry, a user with that name already exists.']]);

    $result = $this->module->create(new ProvisioningRequest(
        serviceId: 'svc_1',
        server: $this->server,
        package: 'starter',
        domain: 'bob.test',
        username: 'bobhost',
        email: null,
    ));

    expect($result->outcome)->toBe(OperationOutcome::AlreadyDone)
        ->and($result->isSuccessful())->toBeTrue();
});

it('reads failure out of the body, not the status code', function (): void {
    // WHM answers 200 and says no.
    whm(['metadata' => ['result' => 0, 'reason' => 'Invalid package name']]);

    $result = $this->module->create(new ProvisioningRequest(
        serviceId: 'svc_1',
        server: $this->server,
        package: 'nonsense',
        domain: 'bob.test',
        username: null,
        email: null,
    ));

    expect($result->outcome)->toBe(OperationOutcome::Failed)
        ->and($result->message)->toBe('Invalid package name');
});

it('suspends, unsuspends and terminates by account name', function (): void {
    whm(['metadata' => ['result' => 1]]);

    expect($this->module->suspend($this->reference, 'Non-payment')->isSuccessful())->toBeTrue()
        ->and($this->module->unsuspend($this->reference)->isSuccessful())->toBeTrue()
        ->and($this->module->terminate($this->reference)->isSuccessful())->toBeTrue();

    Http::assertSent(fn ($request): bool => str_contains($request->url(), 'suspendacct')
        && $request['user'] === 'bobhost');
});

it('treats an account that is already gone as already done', function (): void {
    whm(['metadata' => ['result' => 0, 'reason' => 'User does not exist']]);

    expect($this->module->terminate($this->reference)->outcome)
        ->toBe(OperationOutcome::AlreadyDone);
});

it('refuses to act on a service with no account name', function (): void {
    $nameless = new ServiceReference('svc_2', $this->server, null, null, 'bob.test', 'starter');

    // Asking WHM to suspend "" would suspend nothing and report success.
    expect($this->module->suspend($nameless)->outcome)->toBe(OperationOutcome::Failed);

    Http::assertNothingSent();
});

it('changes a package', function (): void {
    whm(['metadata' => ['result' => 1]]);

    $this->module->changePackage($this->reference, new PackageChange('starter', 'business'));

    Http::assertSent(fn ($request): bool => str_contains($request->url(), 'changepackage')
        && $request['pkg'] === 'business');
});

it('reads a suspended account back from a sync', function (): void {
    whm([
        'metadata' => ['result' => 1],
        'acct' => [['suspended' => 1, 'diskused' => '120M', 'disklimit' => '10240M']],
    ]);

    $sync = $this->module->sync($this->reference);

    expect($sync->reachable)->toBeTrue()
        ->and($sync->remoteStatus)->toBe(ServiceStatus::Suspended)
        ->and($sync->usage['disk_used'])->toBe('120M');
});

it('tests a connection and says what answered', function (): void {
    whm(['metadata' => ['result' => 1], 'version' => '110.0.17']);

    $result = $this->module->testConnection($this->server);

    expect($result->reachable)->toBeTrue()
        ->and($result->version)->toBe('110.0.17')
        ->and($result->durationMs)->not->toBeNull();
});

it('turns a network failure into a result rather than an exception', function (): void {
    Http::fake(fn () => throw new RuntimeException('Connection timed out'));

    $result = $this->module->create(new ProvisioningRequest(
        serviceId: 'svc_1',
        server: $this->server,
        package: 'starter',
        domain: 'bob.test',
        username: null,
        email: null,
    ));

    // A provider being unreachable is a state the platform records, not a
    // 500 for whoever happened to trigger the job.
    expect($result->outcome)->toBe(OperationOutcome::Failed)
        ->and($result->message)->toContain('Connection timed out');
});

it('never prints the token, whatever prints the connection', function (): void {
    $printed = print_r($this->server, true);

    expect($printed)->not->toContain('whm-token-value')
        ->and($printed)->toContain('[redacted]');
});
