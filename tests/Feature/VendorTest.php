<?php

declare(strict_types=1);

use App\Application\Access\SyncPermissions;
use App\Application\Reliability\GatherObservations;
use App\Domain\Access\PermissionRegistry;
use App\Domain\Access\RoleScope;
use App\Domain\Access\SystemRole;
use App\Domain\Organizations\OrganizationType;
use App\Domain\Reliability\AlertSubject;
use App\Domain\Vendors\ContractTerm;
use App\Domain\Vendors\VendorKind;
use App\Infrastructure\Access\Models\Permission;
use App\Infrastructure\Access\Models\Role;
use App\Infrastructure\Identity\Models\StaffUser;
use App\Infrastructure\Organizations\Models\Organization;
use App\Infrastructure\Vendors\Models\Contract;
use App\Infrastructure\Vendors\Models\Vendor;
use App\Support\Organizations\OrganizationContext;
use Carbon\CarbonImmutable;
use Database\Seeders\ProviderOrganizationSeeder;
use Database\Seeders\SystemRoleSeeder;

/**
 * Who this business buys from, and what it agreed (§24).
 *
 * The arithmetic that earns its tests is the **decision date**: on an
 * auto-renewing contract it is not the end date, and the list exists to show
 * somebody a deadline they can still act on.
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
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

function aVendor(VendorKind $kind = VendorKind::Transit): Vendor
{
    return Vendor::factory()->of($kind)->create(['organization_id' => test()->provider->id]);
}

/**
 * @param  list<string>  $slugs
 */
function vendorStaffWith(array $slugs): StaffUser
{
    $role = Role::query()->create([
        'name' => 'Vendors limited',
        'slug' => 'vendors-limited-'.uniqid(),
        'scope' => RoleScope::Staff->value,
        'is_system' => false,
    ]);

    $role->permissions()->sync(Permission::query()->whereIn('slug', $slugs)->pluck('id'));

    $staff = StaffUser::factory()->create(['organization_id' => test()->provider->id]);
    $staff->roles()->attach($role);

    return $staff->fresh() ?? $staff;
}

/**
 * The one that would show somebody a deadline they had already missed.
 */
it('counts to the decision, not to the end, on an auto-renewing contract', function (): void {
    CarbonImmutable::setTestNow('2026-10-04 09:00:00');

    $contract = Contract::factory()
        ->autoRenewing(noticeDays: 60)
        ->create([
            'vendor_id' => aVendor()->id,
            'organization_id' => $this->provider->id,
            'ends_on' => CarbonImmutable::parse('2026-12-31'),
        ]);

    // The end is 88 days away; the last day to say no is 28.
    expect($contract->decideBy()?->toDateString())->toBe('2026-11-01')
        ->and($contract->daysRemaining())->toBe(28);
});

it('counts to the end when nobody asked for notice', function (): void {
    CarbonImmutable::setTestNow('2026-10-04 09:00:00');

    $contract = Contract::factory()->create([
        'vendor_id' => aVendor()->id,
        'organization_id' => $this->provider->id,
        'ends_on' => CarbonImmutable::parse('2026-10-14'),
    ]);

    expect($contract->daysRemaining())->toBe(10);
});

/**
 * Negative all the way through: a contract whose notice period closed last
 * night is the one somebody most needs to hear about.
 */
it('goes negative rather than clamping at zero', function (): void {
    CarbonImmutable::setTestNow('2026-10-04 09:00:00');

    $contract = Contract::factory()->create([
        'vendor_id' => aVendor()->id,
        'organization_id' => $this->provider->id,
        'ends_on' => CarbonImmutable::parse('2026-09-30'),
    ]);

    expect($contract->daysRemaining())->toBe(-4);
});

it('answers nothing at all for a rolling agreement', function (): void {
    $contract = Contract::factory()->rolling()->create([
        'vendor_id' => aVendor()->id,
        'organization_id' => $this->provider->id,
    ]);

    // A contract with no end date is not overdue and never will be. Zero
    // would make it the most urgent row on the screen.
    expect($contract->decideBy())->toBeNull()
        ->and($contract->daysRemaining())->toBeNull();
});

it('keeps a one-off purchase out of the monthly arithmetic', function (): void {
    // Null rather than zero: zero divides into a monthly figure and produces
    // one, which is spreading a one-off across months.
    expect(ContractTerm::Once->months())->toBeNull()
        ->and(ContractTerm::Yearly->months())->toBe(12);
});

it('offers every contract to the alert rules, including the expired ones', function (): void {
    CarbonImmutable::setTestNow('2026-10-04 09:00:00');

    $vendor = aVendor();

    Contract::factory()->create([
        'vendor_id' => $vendor->id,
        'organization_id' => $this->provider->id,
        'title' => 'Transit, 10G',
        'ends_on' => CarbonImmutable::parse('2026-09-30'),
    ]);

    Contract::factory()->rolling()->create([
        'vendor_id' => $vendor->id,
        'organization_id' => $this->provider->id,
        'title' => 'Rolling support',
    ]);

    $observations = app(GatherObservations::class)
        ->for(AlertSubject::ContractExpiry, null, $this->provider->id);

    // "Below 30" has to catch "minus 4", and a rolling agreement produces
    // nothing rather than a zero.
    expect($observations)->toHaveCount(1)
        ->and($observations[0]->value)->toBe(-4.0)
        // The vendor beside the title: "Transit, 10G" alone does not say who
        // somebody has to telephone.
        ->and($observations[0]->label)->toContain($vendor->name)
        ->and($observations[0]->label)->toContain('Transit, 10G');
});

it('adds a vendor and a contract from the screen', function (): void {
    $this->actingAs($this->admin, 'staff')
        ->get('/admin/vendors')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Admin/Vendors/Index')->where('can.manage', true));

    $this->actingAs($this->admin, 'staff')
        ->post('/admin/vendors', ['name' => 'Acme Transit', 'kind' => 'transit'])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $vendor = Vendor::query()->firstOrFail();

    $this->actingAs($this->admin, 'staff')
        ->post('/admin/vendors/'.$vendor->id.'/contracts', [
            'title' => '10G transit',
            'term' => 'yearly',
            'currency_code' => 'EUR',
            'amount_minor' => 1_200_00,
            'ends_on' => '2027-06-30',
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect(Contract::query()->firstOrFail()->amount_minor)->toBe(120000);
});

it('refuses to delete a vendor that still has contracts', function (): void {
    $vendor = aVendor();

    Contract::factory()->create([
        'vendor_id' => $vendor->id,
        'organization_id' => $this->provider->id,
    ]);

    // The migration cascades because a database has to answer something when
    // an organization goes; an operator pressing Delete has not asked to
    // lose four renewal dates.
    $this->actingAs($this->admin, 'staff')
        ->delete('/admin/vendors/'.$vendor->id)
        ->assertRedirect()
        ->assertSessionHasErrors('vendor');

    expect(Vendor::query()->whereKey($vendor->id)->exists())->toBeTrue();
});

it('upper-cases the currency it was given', function (): void {
    $vendor = aVendor();

    // `CreateClient` learned this on a country code. A row holding `usd` is a
    // row nothing else in the installation groups with, and the symptom is a
    // figure that quietly never adds up.
    $this->actingAs($this->admin, 'staff')
        ->post('/admin/vendors/'.$vendor->id.'/contracts', [
            'title' => 'Typed in a hurry',
            'term' => 'monthly',
            'currency_code' => 'usd',
            'amount_minor' => 100,
        ])
        ->assertSessionHasNoErrors();

    expect(Contract::query()->firstOrFail()->currency_code)->toBe('USD');
});

it('refuses a contract that ends before it starts', function (): void {
    $vendor = aVendor();

    $this->actingAs($this->admin, 'staff')
        ->post('/admin/vendors/'.$vendor->id.'/contracts', [
            'title' => 'Backwards',
            'term' => 'yearly',
            'currency_code' => 'EUR',
            'amount_minor' => 100,
            'starts_on' => '2027-01-01',
            'ends_on' => '2026-01-01',
        ])
        ->assertSessionHasErrors('ends_on');
});

it('accepts a contract that starts and ends on one day', function (): void {
    $vendor = aVendor();

    // Somebody's weekend maintenance window. `after` rather than
    // `after_or_equal` would be this form having an opinion it has not earned.
    $this->actingAs($this->admin, 'staff')
        ->post('/admin/vendors/'.$vendor->id.'/contracts', [
            'title' => 'One day',
            'term' => 'once',
            'currency_code' => 'EUR',
            'amount_minor' => 100,
            'starts_on' => '2027-01-01',
            'ends_on' => '2027-01-01',
        ])
        ->assertSessionHasNoErrors();
});

it('refuses the screen to somebody without the permission', function (): void {
    $nobody = StaffUser::factory()->create(['organization_id' => $this->provider->id]);

    $this->actingAs($nobody->fresh(), 'staff')
        ->get('/admin/vendors')
        ->assertForbidden();
});

it('lets somebody read vendors without letting them change one', function (): void {
    $reader = vendorStaffWith(['vendors.view']);

    $this->actingAs($reader, 'staff')
        ->get('/admin/vendors')
        ->assertOk()
        // Reading when transit renews and changing what it says it costs are
        // different jobs.
        ->assertInertia(fn ($page) => $page->where('can.manage', false));

    $this->actingAs($reader, 'staff')
        ->post('/admin/vendors', ['name' => 'Sneaky', 'kind' => 'transit'])
        ->assertForbidden();
});

it('keeps the provider’s own contracts out of a reseller’s list', function (): void {
    $reseller = Organization::factory()->create([
        'parent_id' => $this->provider->id,
        'type' => OrganizationType::Reseller,
    ]);

    Contract::factory()->create([
        'vendor_id' => aVendor()->id,
        'organization_id' => $this->provider->id,
        'title' => 'Our transit',
    ]);

    /*
     * The boundary is a subtree, so the provider sees its reseller's
     * suppliers — which is right: a reseller it hosts is part of its own
     * business. What must never happen is the other direction, because what
     * the provider pays for transit is the reseller's cost of goods.
     */
    app(OrganizationContext::class)->set($reseller->id);

    expect(Contract::query()->count())->toBe(0)
        ->and(Vendor::query()->count())->toBe(0);
});
