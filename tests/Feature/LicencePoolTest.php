<?php

declare(strict_types=1);

use App\Application\Access\SyncPermissions;
use App\Application\Vendors\LicenceCoverage;
use App\Domain\Access\PermissionRegistry;
use App\Domain\Access\SystemRole;
use App\Domain\Organizations\OrganizationType;
use App\Domain\Provisioning\ServerStatus;
use App\Infrastructure\Identity\Models\StaffUser;
use App\Infrastructure\Organizations\Models\Organization;
use App\Infrastructure\Provisioning\Models\Server;
use App\Infrastructure\Vendors\Models\LicenceAllocation;
use App\Infrastructure\Vendors\Models\LicencePool;
use App\Infrastructure\Vendors\Models\Vendor;
use App\Support\Organizations\OrganizationContext;
use Database\Seeders\ProviderOrganizationSeeder;
use Database\Seeders\SystemRoleSeeder;

/**
 * Operational licences, and which machine is using one (§24).
 *
 * What earns the tests is the **difference**: every vendor portal can say how
 * many seats were bought, and only this installation can say which of its
 * machines are doing anything with them. Each of the three answers is a
 * different kind of bad and none of them may quietly become another.
 */
beforeEach(function (): void {
    $this->seed(ProviderOrganizationSeeder::class);
    app(SyncPermissions::class)->handle(app(PermissionRegistry::class));
    $this->seed(SystemRoleSeeder::class);

    $this->provider = Organization::query()
        ->where('type', OrganizationType::Provider->value)
        ->firstOrFail();

    app(OrganizationContext::class)->set($this->provider->id);

    $this->admin = StaffUser::factory()->create(['organization_id' => $this->provider->id]);
    $this->admin->assignRole(SystemRole::Administrator);
    $this->admin = $this->admin->fresh();

    $this->vendor = Vendor::factory()->create(['organization_id' => $this->provider->id]);
});

function licencePool(int $seats = 10, ?string $module = null): LicencePool
{
    $pool = LicencePool::factory()->seats($seats);

    if ($module !== null) {
        $pool = $pool->forModule($module);
    }

    return $pool->create([
        'organization_id' => test()->provider->id,
        'vendor_id' => test()->vendor->id,
    ]);
}

function licensedServer(string $module = 'cpanel', ServerStatus $status = ServerStatus::Active): Server
{
    return Server::factory()->create([
        'organization_id' => test()->provider->id,
        'module' => $module,
        'status' => $status->value,
    ]);
}

it('counts seats nobody is using', function (): void {
    $pool = licencePool(seats: 10);

    LicenceAllocation::factory()->count(3)->sequence(
        fn ($sequence): array => ['server_name' => 'web-'.$sequence->index],
    )->create([
        'licence_pool_id' => $pool->id,
        'organization_id' => $this->provider->id,
    ]);

    expect(app(LicenceCoverage::class)->summary()['spare'])->toBe(7);
});

/**
 * The expensive one, and the figure a platform is most tempted to hide.
 */
it('shows an overage rather than clamping it at zero', function (): void {
    $pool = licencePool(seats: 2);

    foreach (range(1, 5) as $index) {
        LicenceAllocation::factory()->create([
            'licence_pool_id' => $pool->id,
            'organization_id' => $this->provider->id,
            'server_name' => 'web-'.$index,
        ]);
    }

    $summary = app(LicenceCoverage::class)->summary();

    expect($pool->fresh()->spare())->toBe(-3)
        // Spare is clamped only in the summary, and only so that one pool's
        // overage cannot silently cancel another's genuinely idle seats.
        ->and($summary['spare'])->toBe(0)
        ->and($summary['overage'])->toBe(3);
});

it('keeps a seat visible when the machine it was on is deleted', function (): void {
    $server = licensedServer();
    $pool = licencePool();

    LicenceAllocation::factory()->on($server)->create([
        'licence_pool_id' => $pool->id,
        'organization_id' => $this->provider->id,
    ]);

    $name = $server->name;
    $server->delete();

    $orphaned = app(LicenceCoverage::class)->orphaned();

    // Cascading would make the money invisible at exactly the moment it
    // starts being wasted, and the copied name is the only thing left to
    // read.
    expect($orphaned)->toHaveCount(1)
        ->and($orphaned->first()?->server_id)->toBeNull()
        ->and($orphaned->first()?->server_name)->toBe($name);
});

it('counts a seat on a switched-off machine as wasted and one in maintenance as not', function (): void {
    $pool = licencePool();

    LicenceAllocation::factory()->on(licensedServer(status: ServerStatus::Offline))->create([
        'licence_pool_id' => $pool->id,
        'organization_id' => $this->provider->id,
    ]);

    // A machine somebody took out of rotation this morning is going back in
    // this afternoon. Reporting its licence as orphaned would make the list
    // say something different every hour.
    LicenceAllocation::factory()->on(licensedServer(status: ServerStatus::Maintenance))->create([
        'licence_pool_id' => $pool->id,
        'organization_id' => $this->provider->id,
    ]);

    expect(app(LicenceCoverage::class)->summary()['orphaned'])->toBe(1);
});

it('asks about the gap per pool, so a second licence on one machine is still missing', function (): void {
    $server = licensedServer('cpanel');

    $panel = licencePool(seats: 5, module: 'cpanel');
    $security = licencePool(seats: 5, module: 'cpanel');

    LicenceAllocation::factory()->on($server)->create([
        'licence_pool_id' => $panel->id,
        'organization_id' => $this->provider->id,
    ]);

    $gaps = app(LicenceCoverage::class)->missing();

    // Asked once across every pool, this machine would look fully licensed —
    // which is exactly the Imunify renewal nobody noticed had lapsed.
    expect($gaps)->toHaveCount(1)
        ->and($gaps[0]['pool']->id)->toBe($security->id)
        ->and($gaps[0]['servers']->pluck('id')->all())->toBe([$server->id]);
});

it('makes no claim at all about a pool that names no module', function (): void {
    licensedServer('cpanel');
    licencePool(seats: 5);

    // Core has no way to know which machines an Imunify licence belongs on,
    // and a guess would be a list of four hundred servers that each need
    // nothing.
    expect(app(LicenceCoverage::class)->missing())->toBe([])
        ->and(app(LicenceCoverage::class)->summary()['missing'])->toBe(0);
});

it('leaves a switched-off machine out of the gap', function (): void {
    licensedServer('cpanel', ServerStatus::Offline);
    licencePool(seats: 5, module: 'cpanel');

    // A decommissioned box beside the live one that really is running
    // unlicensed would bury the finding.
    expect(app(LicenceCoverage::class)->summary()['missing'])->toBe(0);
});

it('allocates a seat from the screen and copies the machine’s name', function (): void {
    $pool = licencePool();
    $server = licensedServer();

    $this->actingAs($this->admin, 'staff')
        ->get('/admin/vendors/licences')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Admin/Vendors/Licences'));

    $this->actingAs($this->admin, 'staff')
        ->post('/admin/vendors/licences/'.$pool->id.'/allocations', [
            'server_id' => $server->id,
            'reference' => 'CP-99120',
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect(LicenceAllocation::query()->firstOrFail()->server_name)->toBe($server->name);
});

it('refuses a second seat of one pool on one machine, in words', function (): void {
    $pool = licencePool();
    $server = licensedServer();

    LicenceAllocation::factory()->on($server)->create([
        'licence_pool_id' => $pool->id,
        'organization_id' => $this->provider->id,
    ]);

    // The unique index would refuse it as a 500. "That machine already holds
    // one" is an answer rather than a failure.
    $this->actingAs($this->admin, 'staff')
        ->post('/admin/vendors/licences/'.$pool->id.'/allocations', ['server_id' => $server->id])
        ->assertSessionHasErrors('server_id');

    expect(LicenceAllocation::query()->count())->toBe(1);
});

it('changes a licence from the screen', function (): void {
    $pool = licencePool(seats: 10);

    $this->actingAs($this->admin, 'staff')
        ->put('/admin/vendors/licences/'.$pool->id, [
            'vendor_id' => $this->vendor->id,
            'name' => 'cPanel Admin, 200 accounts',
            'for_module' => 'cpanel',
            'seats' => 200,
            'currency_code' => 'USD',
            'unit_amount_minor' => 4_00,
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect($pool->fresh()->seats)->toBe(200)
        ->and($pool->fresh()->for_module)->toBe('cpanel');
});

it('takes a seat back from the screen', function (): void {
    $pool = licencePool();

    $allocation = LicenceAllocation::factory()->on(licensedServer())->create([
        'licence_pool_id' => $pool->id,
        'organization_id' => $this->provider->id,
    ]);

    $this->actingAs($this->admin, 'staff')
        ->delete('/admin/vendors/allocations/'.$allocation->id)
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect(LicenceAllocation::query()->count())->toBe(0);
});

it('refuses to delete a licence that still has seats on machines', function (): void {
    $pool = licencePool();

    LicenceAllocation::factory()->create([
        'licence_pool_id' => $pool->id,
        'organization_id' => $this->provider->id,
    ]);

    $this->actingAs($this->admin, 'staff')
        ->delete('/admin/vendors/licences/'.$pool->id)
        ->assertSessionHasErrors('pool');

    expect(LicencePool::query()->whereKey($pool->id)->exists())->toBeTrue();
});

it('writes no licence key, because there is nowhere for one to go', function (): void {
    // A key is a credential for somebody's production panel and nothing here
    // would ever read one (non-negotiable 7). The guard is the column list.
    expect(Schema::getColumnListing('licence_allocations'))
        ->not->toContain('licence_key')
        ->not->toContain('key')
        ->not->toContain('secret');
});

it('upper-cases the currency a pool was given', function (): void {
    $this->actingAs($this->admin, 'staff')
        ->post('/admin/vendors/licences', [
            'vendor_id' => $this->vendor->id,
            'name' => 'CloudLinux',
            'seats' => 20,
            'currency_code' => 'usd',
            'unit_amount_minor' => 1400,
        ])
        ->assertSessionHasNoErrors();

    expect(LicencePool::query()->firstOrFail()->currency_code)->toBe('USD');
});

it('reads an empty select as nothing stated rather than as an empty string', function (): void {
    $this->actingAs($this->admin, 'staff')
        ->post('/admin/vendors/licences', [
            'vendor_id' => $this->vendor->id,
            'name' => 'LiteSpeed',
            'for_module' => '',
            'contract_id' => '',
            'seats' => 4,
            'currency_code' => 'EUR',
            'unit_amount_minor' => 0,
        ])
        ->assertSessionHasNoErrors();

    // The ticket screen wrote `''` into a ULID column for want of exactly
    // this, and a `for_module` of `''` would match a module nothing has.
    $pool = LicencePool::query()->firstOrFail();

    expect($pool->for_module)->toBeNull()
        ->and($pool->contract_id)->toBeNull();
});

it('refuses the screen to somebody without the permission', function (): void {
    $nobody = StaffUser::factory()->create(['organization_id' => $this->provider->id]);

    $this->actingAs($nobody->fresh(), 'staff')
        ->get('/admin/vendors/licences')
        ->assertForbidden();
});
