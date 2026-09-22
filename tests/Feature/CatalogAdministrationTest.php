<?php

declare(strict_types=1);

use App\Application\Access\SyncPermissions;
use App\Domain\Access\PermissionRegistry;
use App\Domain\Access\RoleScope;
use App\Domain\Access\SystemRole;
use App\Domain\Catalog\BillingCycle;
use App\Domain\Catalog\CatalogStatus;
use App\Domain\Catalog\OptionType;
use App\Domain\Catalog\ProductType;
use App\Infrastructure\Access\Models\Permission;
use App\Infrastructure\Access\Models\Role;
use App\Infrastructure\Catalog\Models\Addon;
use App\Infrastructure\Catalog\Models\Option;
use App\Infrastructure\Catalog\Models\OptionGroup;
use App\Infrastructure\Catalog\Models\Product;
use App\Infrastructure\Catalog\Models\ProductGroup;
use App\Infrastructure\Identity\Models\StaffUser;
use App\Infrastructure\Organizations\Models\Organization;
use App\Infrastructure\Shared\Models\CurrencyRecord;
use Database\Seeders\ProviderOrganizationSeeder;
use Database\Seeders\SystemRoleSeeder;
use Inertia\Testing\AssertableInertia;

beforeEach(function (): void {
    $this->withoutVite();

    $this->seed(ProviderOrganizationSeeder::class);
    app(SyncPermissions::class)->handle(app(PermissionRegistry::class));
    $this->seed(SystemRoleSeeder::class);

    $this->admin = StaffUser::factory()->create();
    $this->admin->assignRole(SystemRole::Administrator);
    $this->admin = $this->admin->fresh();

    $this->currency = CurrencyRecord::factory()
        ->forOrganization($this->admin->organization_id)
        ->base()
        ->code('EUR', 'Euro')
        ->create();
});

it('refuses the catalog without the permission', function (): void {
    $this->actingAs(StaffUser::factory()->create(), 'staff')
        ->get('/admin/catalog/products')
        ->assertForbidden();
});

it('lists product groups', function (): void {
    ProductGroup::factory()->forOrganization($this->admin->organization_id)->create(['name' => 'Shared Hosting']);

    $this->actingAs($this->admin, 'staff')
        ->get('/admin/catalog/groups')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->component('Admin/Catalog/Groups/Index')
            ->has('groups', 1)
            ->where('groups.0.name', 'Shared Hosting'));
});

it('creates a product group', function (): void {
    $this->actingAs($this->admin, 'staff')
        ->post('/admin/catalog/groups', [
            'name' => 'Shared Hosting',
            'slug' => 'shared-hosting',
            'status' => CatalogStatus::Active->value,
        ])
        ->assertRedirect('/admin/catalog/groups');

    expect(ProductGroup::query()->where('slug', 'shared-hosting')->exists())->toBeTrue();
});

it('refuses a slug that is already taken in the same organization', function (): void {
    ProductGroup::factory()->forOrganization($this->admin->organization_id)->create(['slug' => 'shared-hosting']);

    $this->actingAs($this->admin, 'staff')
        ->post('/admin/catalog/groups', [
            'name' => 'Shared Hosting',
            'slug' => 'shared-hosting',
            'status' => CatalogStatus::Active->value,
        ])
        ->assertSessionHasErrors('slug');
});

it('allows another organization to use the same slug', function (): void {
    $reseller = Organization::factory()->reseller(Organization::query()->whereNull('parent_id')->sole())->create();
    ProductGroup::factory()->forOrganization($reseller->id)->create(['slug' => 'shared-hosting']);

    $this->actingAs($this->admin, 'staff')
        ->post('/admin/catalog/groups', [
            'name' => 'Shared Hosting',
            'slug' => 'shared-hosting',
            'status' => CatalogStatus::Active->value,
        ])
        ->assertSessionHasNoErrors();
});

it('refuses to delete a group that still holds products', function (): void {
    $group = ProductGroup::factory()->forOrganization($this->admin->organization_id)->create();
    Product::factory()->inGroup($group)->create();

    $this->actingAs($this->admin, 'staff')
        ->delete("/admin/catalog/groups/{$group->id}")
        ->assertStatus(412);
});

it('creates a product and sends the operator on to pricing', function (): void {
    $group = ProductGroup::factory()->forOrganization($this->admin->organization_id)->create();

    $this->actingAs($this->admin, 'staff')
        ->post('/admin/catalog/products', [
            'product_group_id' => $group->id,
            'name' => 'Starter Plan',
            'type' => ProductType::SharedHosting->value,
            'status' => CatalogStatus::Active->value,
            'features' => ['10 GB SSD', ''],
        ])
        ->assertRedirectContains('/pricing');

    $product = Product::query()->where('slug', 'starter-plan')->sole();

    // The blank line an operator left behind is not a feature.
    expect($product->features)->toBe(['10 GB SSD'])
        ->and($product->requires_domain)->toBeTrue();
});

it('saves a price matrix', function (): void {
    $product = Product::factory()->create(['organization_id' => $this->admin->organization_id]);

    $this->actingAs($this->admin, 'staff')
        ->put("/admin/catalog/products/{$product->id}/pricing", [
            'prices' => [
                [
                    'billing_cycle' => BillingCycle::Monthly->value,
                    'currency_code' => 'EUR',
                    'recurring_minor' => 999,
                    'setup_minor' => 0,
                ],
                [
                    'billing_cycle' => BillingCycle::Annually->value,
                    'currency_code' => 'EUR',
                    'recurring_minor' => 9990,
                    'setup_minor' => 1500,
                ],
            ],
        ])
        ->assertRedirect("/admin/catalog/products/{$product->id}/pricing");

    $product->load('prices');

    expect($product->prices)->toHaveCount(2)
        ->and($product->recurringFor(BillingCycle::Annually, 'EUR')?->minorUnits)->toBe(9990)
        ->and($product->setupFor(BillingCycle::Annually, 'EUR')?->minorUnits)->toBe(1500);
});

it('refuses a negative product price', function (): void {
    $product = Product::factory()->create(['organization_id' => $this->admin->organization_id]);

    $this->actingAs($this->admin, 'staff')
        ->put("/admin/catalog/products/{$product->id}/pricing", [
            'prices' => [[
                'billing_cycle' => BillingCycle::Monthly->value,
                'currency_code' => 'EUR',
                'recurring_minor' => -999,
                'setup_minor' => 0,
            ]],
        ])
        ->assertSessionHasErrors('prices');

    expect($product->prices()->count())->toBe(0);
});

it('empties a price matrix when nothing is submitted', function (): void {
    $product = Product::factory()->create(['organization_id' => $this->admin->organization_id]);
    $product->prices()->create([
        'organization_id' => $product->organization_id,
        'billing_cycle' => BillingCycle::Monthly->value,
        'currency_code' => 'EUR',
        'recurring_minor' => 999,
        'setup_minor' => 0,
    ]);

    $this->actingAs($this->admin, 'staff')
        ->put("/admin/catalog/products/{$product->id}/pricing", ['prices' => []])
        ->assertSessionHasNoErrors();

    expect($product->prices()->count())->toBe(0);
});

it('refuses pricing to a staff member who may only edit descriptions', function (): void {
    $role = Role::query()->create([
        'name' => 'Catalog editor',
        'slug' => 'catalog-editor',
        'scope' => RoleScope::Staff->value,
        'is_system' => false,
    ]);
    $role->permissions()->sync(
        Permission::query()->whereIn('slug', ['catalog.products.view', 'catalog.products.manage'])->pluck('id'),
    );

    $editor = StaffUser::factory()->create(['organization_id' => $this->admin->organization_id]);
    $editor->roles()->attach($role);

    $product = Product::factory()->create(['organization_id' => $this->admin->organization_id]);

    $this->actingAs($editor->fresh(), 'staff')
        ->get("/admin/catalog/products/{$product->id}/pricing")
        ->assertForbidden();

    $this->actingAs($editor->fresh(), 'staff')
        ->get("/admin/catalog/products/{$product->id}/edit")
        ->assertOk();
});

it('saves an option group with its choices and their price deltas', function (): void {
    $product = Product::factory()->create(['organization_id' => $this->admin->organization_id]);

    $this->actingAs($this->admin, 'staff')
        ->post("/admin/catalog/products/{$product->id}/options", [
            'name' => 'Control panel',
            'key' => 'control_panel',
            'type' => OptionType::Select->value,
            'is_required' => true,
            'options' => [
                [
                    'label' => 'None',
                    'value' => 'none',
                    'is_default' => true,
                    'position' => 0,
                    'prices' => [[
                        'billing_cycle' => BillingCycle::Monthly->value,
                        'currency_code' => 'EUR',
                        'recurring_minor' => -300,
                        'setup_minor' => 0,
                    ]],
                ],
                [
                    'label' => 'cPanel',
                    'value' => 'cpanel',
                    'is_default' => false,
                    'position' => 1,
                    'prices' => [[
                        'billing_cycle' => BillingCycle::Monthly->value,
                        'currency_code' => 'EUR',
                        'recurring_minor' => 500,
                        'setup_minor' => 0,
                    ]],
                ],
            ],
        ])
        ->assertRedirect("/admin/catalog/products/{$product->id}/options");

    $group = OptionGroup::query()->where('key', 'control_panel')->sole();
    $none = Option::query()->where('value', 'none')->sole();
    $none->load('prices');

    expect($group->options()->count())->toBe(2)
        ->and($none->recurringFor(BillingCycle::Monthly, 'EUR')?->minorUnits)->toBe(-300);
});

it('refuses an option key that is not machine safe', function (): void {
    $product = Product::factory()->create(['organization_id' => $this->admin->organization_id]);

    $this->actingAs($this->admin, 'staff')
        ->post("/admin/catalog/products/{$product->id}/options", [
            'name' => 'Control panel',
            'key' => 'Control Panel',
            'type' => OptionType::Select->value,
            'options' => [],
        ])
        ->assertSessionHasErrors('key');
});

it('saves an addon and its prices separately', function (): void {
    $product = Product::factory()->create(['organization_id' => $this->admin->organization_id]);

    $this->actingAs($this->admin, 'staff')
        ->post("/admin/catalog/products/{$product->id}/addons", [
            'name' => 'Dedicated IP',
            'status' => CatalogStatus::Active->value,
        ])
        ->assertSessionHasNoErrors();

    $addon = Addon::query()->where('slug', 'dedicated-ip')->sole();

    $this->actingAs($this->admin, 'staff')
        ->put("/admin/catalog/products/{$product->id}/addons/{$addon->id}/pricing", [
            'prices' => [[
                'billing_cycle' => BillingCycle::Monthly->value,
                'currency_code' => 'EUR',
                'recurring_minor' => 200,
                'setup_minor' => 0,
            ]],
        ])
        ->assertSessionHasNoErrors();

    $addon->load('prices');

    expect($addon->recurringFor(BillingCycle::Monthly, 'EUR')?->minorUnits)->toBe(200);
});

it('adds a currency and takes its decimals from ISO', function (): void {
    $this->actingAs($this->admin, 'staff')
        ->post('/admin/catalog/currencies', [
            'code' => 'jpy',
            'name' => 'Japanese Yen',
            'rate' => '162.50000000',
            'is_active' => true,
        ])
        ->assertRedirect('/admin/catalog/currencies');

    $record = CurrencyRecord::query()->where('code', 'JPY')->sole();

    expect($record->exponent)->toBe(0)
        ->and($record->snapshots()->count())->toBe(1);
});

it('refuses a rate that is not a decimal', function (): void {
    $this->actingAs($this->admin, 'staff')
        ->post('/admin/catalog/currencies', [
            'code' => 'USD',
            'name' => 'US Dollar',
            'rate' => '1,08',
        ])
        ->assertSessionHasErrors('rate');
});

it('refuses to delete the base currency', function (): void {
    $this->actingAs($this->admin, 'staff')
        ->delete("/admin/catalog/currencies/{$this->currency->id}")
        ->assertStatus(412);
});

it('does not reach a product belonging to another organization', function (): void {
    $reseller = Organization::factory()->reseller(Organization::query()->whereNull('parent_id')->sole())->create();
    $theirs = Product::factory()->create(['organization_id' => $reseller->id]);

    $staff = StaffUser::factory()->create(['organization_id' => $reseller->id]);

    // The provider's administrator owns the reseller, so this one is
    // reachable; the reverse is not.
    $this->actingAs($staff, 'staff')
        ->get("/admin/catalog/products/{$theirs->id}/edit")
        ->assertForbidden();

    $mine = Product::factory()->create(['organization_id' => $this->admin->organization_id]);

    $this->actingAs($staff, 'staff')
        ->get("/admin/catalog/products/{$mine->id}/edit")
        ->assertNotFound();
});
