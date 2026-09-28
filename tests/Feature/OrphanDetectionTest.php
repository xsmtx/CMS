<?php

declare(strict_types=1);

use App\Application\Access\SyncPermissions;
use App\Application\Infrastructure\ResourceGraph;
use App\Application\Intelligence\DetectOrphans;
use App\Application\Intelligence\ProposeRemediation;
use App\Application\Intelligence\RecordFindings;
use App\Domain\Access\PermissionRegistry;
use App\Domain\Access\SystemRole;
use App\Domain\Intelligence\ReconciliationClass;
use App\Domain\Intelligence\RemediationAction;
use App\Domain\Organizations\OrganizationType;
use App\Domain\Provisioning\ServiceStatus;
use App\Infrastructure\Identity\Models\StaffUser;
use App\Infrastructure\Intelligence\Models\ReconciliationFinding;
use App\Infrastructure\Network\Models\IpAddressRecord;
use App\Infrastructure\Network\Models\IpPool;
use App\Infrastructure\Network\Models\IpPrefixRecord;
use App\Infrastructure\Organizations\Models\Organization;
use App\Infrastructure\Provisioning\Models\Service;
use App\Support\Organizations\OrganizationContext;
use Database\Seeders\ProviderOrganizationSeeder;
use Database\Seeders\SystemRoleSeeder;

/**
 * Orphan detection (§21).
 *
 * Reconciliation read from the other end: `ReconcileServices` starts with a
 * row and asks the provider about it; this starts with what the providers
 * reported and asks whether anything here owns it. A machine built by hand
 * for a migration and never recorded is invisible to the first and is exactly
 * what the second is for.
 *
 * **An orphan is a finding, never a verdict**, and every kind here has a
 * legitimate reason to exist without a row: a machine built for a migration,
 * an address held for a customer arriving next week. So nothing offers a
 * delete, and where there is no row at all this platform cannot even reach
 * the thing — which is why removal is not among the actions offered.
 */
beforeEach(function (): void {
    $this->seed(ProviderOrganizationSeeder::class);
    app(SyncPermissions::class)->handle(app(PermissionRegistry::class));
    $this->seed(SystemRoleSeeder::class);

    $this->provider = Organization::query()
        ->where('type', OrganizationType::Provider->value)
        ->firstOrFail();

    app(OrganizationContext::class)->set($this->provider->id);

    $this->admin = StaffUser::factory()->create();
    $this->admin->assignRole(SystemRole::Administrator);
    $this->admin = $this->admin->fresh();

    $this->orphans = app(DetectOrphans::class);
});

/**
 * @param  array<string, mixed>  $attributes
 */
function discovered(Organization $provider, string $kind, string $key, string $label, array $attributes = []): void
{
    app(ResourceGraph::class)->upsertNode(
        organizationId: $provider->id,
        kind: $kind,
        nodeKey: $key,
        label: $label,
        source: 'test-discovery',
        attributes: $attributes,
    );
}

it('says nothing about a machine a service points at', function (): void {
    Service::factory()->create([
        'organization_id' => $this->provider->id,
        'module' => 'fake',
        'external_id' => 'vm-9001',
        'status' => ServiceStatus::Active,
    ]);

    discovered($this->provider, 'virtual_machine', 'prox/vm-9001', 'shop-vm', [
        'machine_key' => 'vm-9001',
        'adapter' => 'proxmox',
    ]);

    expect($this->orphans->handle($this->provider->id))->toBe([]);
});

it('finds a machine nobody here owns', function (): void {
    discovered($this->provider, 'virtual_machine', 'prox/vm-4242', 'migration-temp', [
        'machine_key' => 'vm-4242',
        'adapter' => 'proxmox',
    ]);

    $found = $this->orphans->handle($this->provider->id);

    expect($found)->toHaveCount(1)
        ->and($found[0]->class)->toBe(ReconciliationClass::Orphan)
        ->and($found[0]->resource)->toBe('virtual_machine')
        // No row here at all, which is what makes it an orphan.
        ->and($found[0]->subjectId)->toBeNull()
        // And how sure this is: an external id is an identity.
        ->and($found[0]->detail['matched_on'])->toBe('external_id');
});

it('matches a site on its domain and says that is what it matched on', function (): void {
    Service::factory()->create([
        'organization_id' => $this->provider->id,
        'domain' => 'shop.example',
        'status' => ServiceStatus::Active,
    ]);

    discovered($this->provider, 'site', 'wptoolkit/i1', 'https://shop.example', [
        'url' => 'https://shop.example',
        'application' => 'wordpress',
    ]);
    discovered($this->provider, 'site', 'wptoolkit/i2', 'https://nobody.example', [
        'url' => 'https://nobody.example',
        'application' => 'wordpress',
    ]);

    $found = $this->orphans->handle($this->provider->id);

    expect($found)->toHaveCount(1)
        ->and($found[0]->label)->toBe('https://nobody.example')
        // A name rather than an identity, and the row says so — an operator
        // deciding whether to destroy something deserves to know how sure
        // the platform is.
        ->and($found[0]->detail['matched_on'])->toBe('domain');
});

/**
 * `2001:db8::1` and its expanded spelling are one address. A text comparison
 * would report every IPv6 address on the network as an orphan.
 */
it('matches an address on its bytes rather than on its text', function (): void {
    $pool = IpPool::factory()->create(['organization_id' => $this->provider->id]);
    $prefix = IpPrefixRecord::factory()->inPool($pool)->of('2001:db8::/64')->create();

    IpAddressRecord::factory()->inPrefix($prefix)
        ->of('2001:0db8:0000:0000:0000:0000:0000:0001')
        ->create();

    discovered($this->provider, 'ip_address', 'sw1/eth0/2001:db8::1', '2001:db8::1');

    expect($this->orphans->handle($this->provider->id))->toBe([]);
});

/**
 * A device reports what it is wearing, mask and all. `inet_pton` answers
 * false for `192.0.2.1/24`, and a silent false would drop every address on
 * the network and report no orphans at all.
 */
it('reads an address a device reported with its mask', function (): void {
    $pool = IpPool::factory()->create(['organization_id' => $this->provider->id]);
    $prefix = IpPrefixRecord::factory()->inPool($pool)->of('192.0.2.0/24')->create();

    IpAddressRecord::factory()->inPrefix($prefix)->of('192.0.2.1')->create();

    discovered($this->provider, 'ip_address', 'sw1/eth0/192.0.2.1', '192.0.2.1/24');

    expect($this->orphans->handle($this->provider->id))->toBe([]);
});

it('finds an address the plan does not have', function (): void {
    discovered($this->provider, 'ip_address', 'sw1/eth0/198.51.100.7', '198.51.100.7/24');

    $found = $this->orphans->handle($this->provider->id);

    expect($found)->toHaveCount(1)
        ->and($found[0]->resource)->toBe('ip_address');
});

/**
 * A platform with no hypervisor module reports no orphaned machines rather
 * than reporting that every machine is orphaned. Absence of an adapter is not
 * evidence — the same rule a stale metric and an unread advisory live under.
 */
it('says nothing at all when nothing has been discovered', function (): void {
    Service::factory()->create([
        'organization_id' => $this->provider->id,
        'module' => 'fake',
        'external_id' => 'vm-1',
        'status' => ServiceStatus::Active,
    ]);

    expect($this->orphans->handle($this->provider->id))->toBe([]);
});

/**
 * There is no row here to act through, so this platform cannot reach the
 * machine. A button that was offered and always refused would be worse than
 * no button.
 */
it('offers nothing but investigating on an orphan with no row behind it', function (): void {
    discovered($this->provider, 'virtual_machine', 'prox/vm-4242', 'migration-temp', [
        'machine_key' => 'vm-4242',
    ]);

    app(RecordFindings::class)->handle(
        $this->provider->id,
        DetectOrphans::Resource,
        $this->orphans->handle($this->provider->id),
    );

    $finding = ReconciliationFinding::query()->sole();

    expect(app(ProposeRemediation::class)->available($finding))
        ->toBe([RemediationAction::Investigate]);
});

it('shows the orphans on the queue beside the other findings', function (): void {
    discovered($this->provider, 'virtual_machine', 'prox/vm-4242', 'migration-temp', [
        'machine_key' => 'vm-4242',
    ]);

    app(RecordFindings::class)->handle(
        $this->provider->id,
        DetectOrphans::Resource,
        $this->orphans->handle($this->provider->id),
    );

    $this->actingAs($this->admin, 'staff')
        ->get('/admin/intelligence/reconciliation')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('findings.data', 1)
            ->where('findings.data.0.class', 'orphan')
            ->where('findings.data.0.resource', 'virtual_machine')
            // And its noun is worded rather than printed as a key.
            ->where('findings.data.0.resourceLabel', 'Virtual machine'));
});
