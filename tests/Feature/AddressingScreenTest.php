<?php

declare(strict_types=1);

use App\Application\Access\SyncPermissions;
use App\Application\Network\AllocateAddress;
use App\Application\Network\AssignAddress;
use App\Application\Network\SavePrefix;
use App\Domain\Access\PermissionRegistry;
use App\Domain\Access\SystemRole;
use App\Domain\Network\AddressState;
use App\Domain\Network\IpFamily;
use App\Domain\Network\IpPrefix;
use App\Domain\Network\PoolPurpose;
use App\Domain\Organizations\OrganizationType;
use App\Infrastructure\Identity\Models\StaffUser;
use App\Infrastructure\Network\Models\IpAddressRecord;
use App\Infrastructure\Network\Models\IpPool;
use App\Infrastructure\Network\Models\IpPrefixRecord;
use App\Infrastructure\Organizations\Models\Organization;
use App\Infrastructure\Provisioning\Models\Service;
use App\Support\Organizations\OrganizationContext;
use Database\Seeders\ProviderOrganizationSeeder;
use Database\Seeders\SystemRoleSeeder;
use Inertia\Testing\AssertableInertia;

/**
 * The two addressing screens, rendered and driven.
 *
 * Rendering proves the props; only a request proves the payload the form sends is
 * the payload the controller wants — which is the rule that found three bugs the
 * last time it was applied to a whole area.
 *
 * At least two of everything, because strict mode only reports a lazy load when a
 * query returned more than one row.
 */
beforeEach(function (): void {
    $this->withoutVite();

    $this->seed(ProviderOrganizationSeeder::class);
    app(SyncPermissions::class)->handle(app(PermissionRegistry::class));
    $this->seed(SystemRoleSeeder::class);

    $this->provider = Organization::query()
        ->where('type', OrganizationType::Provider->value)
        ->firstOrFail();

    $this->admin = StaffUser::factory()->create(['organization_id' => $this->provider->id]);
    $this->admin->assignRole(SystemRole::Administrator);
    $this->admin = $this->admin->fresh();

    app(OrganizationContext::class)->set($this->provider->id);

    $this->pool = IpPool::factory()->create([
        'organization_id' => $this->provider->id,
        'name' => 'Customer v4',
        'family' => IpFamily::V4->value,
    ]);

    $this->first = app(SavePrefix::class)->create($this->pool, IpPrefix::parse('192.0.2.0/24'));
    $this->second = app(SavePrefix::class)->create($this->pool, IpPrefix::parse('198.51.100.0/24'));
});

it('renders the networks with how full each one is', function (): void {
    app(AllocateAddress::class)->handle($this->first);

    $this->actingAs($this->admin, 'staff')
        ->get('/admin/network/addressing')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->component('Admin/Network/Addressing')
            ->has('prefixes.data', 2)
            ->where('prefixes.data.0.cidr', '192.0.2.0/24')
            ->where('prefixes.data.0.used', 1)
            // A /24 gives up its network and broadcast addresses.
            ->where('prefixes.data.0.capacity', 254)
            ->has('pools', 1)
            ->where('can.manage', true));
});

/**
 * A /64 is larger than any number a browser should print, and a bar reading
 * 0.0000000001% would say nothing while looking like it said something.
 */
it('declines to give a percentage for a network nobody can count', function (): void {
    $sixes = IpPool::factory()->v6()->create([
        'organization_id' => $this->provider->id,
        'name' => 'Customer v6',
    ]);

    app(SavePrefix::class)->create($sixes, IpPrefix::parse('2001:db8::/64'));

    $this->actingAs($this->admin, 'staff')
        ->get('/admin/network/addressing?pool='.$sixes->id)
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->has('prefixes.data', 1)
            ->where('prefixes.data.0.capacity', null)
            ->where('prefixes.data.0.utilisation', null));
});

it('renders one network with the addresses somebody has acted on', function (): void {
    $address = app(AllocateAddress::class)->handle($this->first);
    app(AssignAddress::class)->handle($address, Service::factory()->create());

    IpAddressRecord::factory()->inPrefix($this->first)->of('192.0.2.50')
        ->state_(AddressState::Reserved)->create();

    $this->actingAs($this->admin, 'staff')
        ->get('/admin/network/addressing/'.$this->first->id)
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->component('Admin/Network/Prefix')
            ->where('prefix.cidr', '192.0.2.0/24')
            ->has('addresses.data', 2)
            // Two fields, as everything crossing to the browser is.
            ->where('addresses.data.0.state', AddressState::Assigned->value)
            ->where('addresses.data.0.stateLabel', __('network.address_states.assigned'))
            ->where('addresses.data.0.holder', Service::query()->sole()->auditLabel()));
});

it('creates a pool from the form the screen sends', function (): void {
    $this->actingAs($this->admin, 'staff')
        ->post('/admin/network/pools', [
            'name' => 'Management',
            'family' => IpFamily::V6->value,
            'purpose' => PoolPurpose::Infrastructure->value,
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect(IpPool::query()->where('name', 'Management')->exists())->toBeTrue();
});

it('creates a network from the form the screen sends', function (): void {
    $this->actingAs($this->admin, 'staff')
        ->post('/admin/network/prefixes', [
            'ip_pool_id' => $this->pool->id,
            'cidr' => '203.0.113.0/24',
            'gateway' => '203.0.113.1',
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect(IpPrefixRecord::query()->where('cidr', '203.0.113.0/24')->exists())->toBeTrue();
});

/**
 * The value object's refusal reaches the form rather than the error handler: a
 * 500 page on a typo is the shape the Modules screen shipped with for a phase.
 */
it('tells the operator what the network would have been', function (): void {
    $this->actingAs($this->admin, 'staff')
        ->post('/admin/network/prefixes', [
            'ip_pool_id' => $this->pool->id,
            'cidr' => '192.0.2.5/24',
        ])
        ->assertRedirect()
        ->assertSessionHasErrors('cidr');

    expect(session('errors')?->first('cidr'))->toContain('192.0.2.0/24');
});

it('takes the next free address and holds it', function (): void {
    $this->actingAs($this->admin, 'staff')
        ->post('/admin/network/prefixes/'.$this->first->id.'/allocate')
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $address = IpAddressRecord::query()->sole();

    expect($address->address)->toBe('192.0.2.1')
        // Reserved rather than available: an address that came back free is
        // one the next allocation could hand to somebody else between two
        // clicks.
        ->and($address->state)->toBe(AddressState::Reserved);
});

it('releases an address from the screen, and quarantines it by default', function (): void {
    $address = app(AllocateAddress::class)->handle($this->first);
    app(AssignAddress::class)->handle($address, Service::factory()->create());

    $this->actingAs($this->admin, 'staff')
        ->post('/admin/network/addresses/'.$address->id.'/release')
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect($address->fresh()?->state)->toBe(AddressState::Quarantined);
});

it('puts an address straight back when the operator asks for that', function (): void {
    $address = app(AllocateAddress::class)->handle($this->first);
    app(AssignAddress::class)->handle($address, Service::factory()->create());

    $this->actingAs($this->admin, 'staff')
        ->post('/admin/network/addresses/'.$address->id.'/release', ['quarantine' => false])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect($address->fresh()?->state)->toBe(AddressState::Available);
});

it('removes an empty network from the screen', function (): void {
    $this->actingAs($this->admin, 'staff')
        ->delete('/admin/network/prefixes/'.$this->second->id)
        ->assertRedirect('/admin/network/addressing')
        ->assertSessionHasNoErrors();

    expect(IpPrefixRecord::query()->whereKey($this->second->id)->exists())->toBeFalse();
});

/**
 * Support reads addressing because an abuse report is an address and a date.
 * Support does not hand addresses out.
 */
it('opens to support for reading and refuses the writes', function (): void {
    $support = StaffUser::factory()->create(['organization_id' => $this->provider->id]);
    $support->assignRole(SystemRole::Support);
    $support = $support->fresh();

    $this->actingAs($support, 'staff')
        ->get('/admin/network/addressing')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->where('can.manage', false));

    $this->actingAs($support, 'staff')
        ->post('/admin/network/prefixes/'.$this->first->id.'/allocate')
        ->assertForbidden();
});

it('does not open to a role that holds neither permission', function (): void {
    $nobody = StaffUser::factory()->create(['organization_id' => $this->provider->id]);

    $this->actingAs($nobody, 'staff')->get('/admin/network/addressing')->assertForbidden();
});

/**
 * A reseller's addressing is their own. This is the rule the whole platform is
 * built on, and a new area is exactly where it gets forgotten.
 */
it('never shows one organization another organizations networks', function (): void {
    $reseller = Organization::factory()->reseller($this->provider)->create();

    $staff = StaffUser::factory()->create(['organization_id' => $reseller->id]);
    $staff->assignRole(SystemRole::Administrator);

    $this->actingAs($staff->fresh(), 'staff')
        ->get('/admin/network/addressing')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->has('prefixes.data', 0));

    $this->actingAs($staff->fresh(), 'staff')
        ->get('/admin/network/addressing/'.$this->first->id)
        ->assertNotFound();
});
