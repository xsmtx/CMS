<?php

declare(strict_types=1);

use App\Application\Access\SyncPermissions;
use App\Domain\Access\PermissionRegistry;
use App\Domain\Access\SystemRole;
use App\Domain\Catalog\CatalogStatus;
use App\Domain\Catalog\OptionType;
use App\Domain\Organizations\OrganizationType;
use App\Infrastructure\Catalog\Models\Addon;
use App\Infrastructure\Catalog\Models\OptionGroup;
use App\Infrastructure\Catalog\Models\Product;
use App\Infrastructure\Catalog\Models\ProductGroup;
use App\Infrastructure\Identity\Models\StaffUser;
use App\Infrastructure\Organizations\Models\Organization;
use App\Infrastructure\Shared\Models\CurrencyRecord;
use App\Support\Organizations\OrganizationContext;
use Database\Seeders\ProviderOrganizationSeeder;
use Database\Seeders\SystemRoleSeeder;

/**
 * The catalog's edit and delete paths.
 *
 * Creating a product was tested; changing one and removing one were not, and
 * they are where the interesting mistakes live. An update carries a uniqueness
 * rule that has to ignore the row being edited — get that wrong and a product
 * can be saved once and never again — and a delete has to refuse when something
 * downstream is still pointing at the record.
 */
beforeEach(function (): void {
    $this->withoutVite();

    $this->seed(ProviderOrganizationSeeder::class);
    app(SyncPermissions::class)->handle(app(PermissionRegistry::class));
    $this->seed(SystemRoleSeeder::class);

    $this->provider = Organization::query()
        ->where('type', OrganizationType::Provider->value)
        ->firstOrFail();

    app(OrganizationContext::class)->set($this->provider->id);

    $this->staff = StaffUser::factory()->create(['organization_id' => $this->provider->id]);
    $this->staff->assignRole(SystemRole::Administrator);
    $this->staff = $this->staff->fresh();

    $this->group = ProductGroup::factory()->create(['organization_id' => $this->provider->id]);
    $this->product = Product::factory()->create([
        'organization_id' => $this->provider->id,
        'product_group_id' => $this->group->id,
    ]);
});

it('saves a product twice, which its own slug rule has to allow', function (): void {
    $payload = [
        'product_group_id' => $this->group->id,
        'name' => 'Starter hosting',
        'slug' => 'starter-hosting',
        'type' => $this->product->type->value,
        'status' => CatalogStatus::Active->value,
        'description' => 'One site, 10 GB.',
        'module' => '',
        'position' => 0,
    ];

    $this->actingAs($this->staff, 'staff')
        ->put('/admin/catalog/products/'.$this->product->id, $payload)
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    expect($this->product->fresh()->name)->toBe('Starter hosting');

    // The second save is the one a uniqueness rule that forgot to ignore the
    // current row would refuse — and the operator would read "slug taken" about
    // the slug they already own.
    $this->actingAs($this->staff, 'staff')
        ->put('/admin/catalog/products/'.$this->product->id, [...$payload, 'name' => 'Starter hosting plus'])
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    expect($this->product->fresh()->name)->toBe('Starter hosting plus');
});

it('deletes a product nothing is using', function (): void {
    $this->actingAs($this->staff, 'staff')
        ->delete('/admin/catalog/products/'.$this->product->id)
        ->assertRedirect();

    expect(Product::query()->whereKey($this->product->id)->exists())->toBeFalse();
});

it('saves a product group twice and then removes it', function (): void {
    $payload = [
        'name' => 'Shared hosting',
        'slug' => 'shared-hosting',
        'description' => 'The cheap end.',
        'status' => CatalogStatus::Active->value,
        'position' => 0,
    ];

    $this->actingAs($this->staff, 'staff')
        ->put('/admin/catalog/groups/'.$this->group->id, $payload)
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    $this->actingAs($this->staff, 'staff')
        ->put('/admin/catalog/groups/'.$this->group->id, [...$payload, 'name' => 'Shared hosting (EU)'])
        ->assertSessionHasNoErrors();

    expect($this->group->fresh()->name)->toBe('Shared hosting (EU)');

    // The product in it goes first: a group is deleted, not a catalogue.
    $this->product->delete();

    $this->actingAs($this->staff, 'staff')
        ->delete('/admin/catalog/groups/'.$this->group->id)
        ->assertRedirect();

    expect(ProductGroup::query()->whereKey($this->group->id)->exists())->toBeFalse();
});

it('edits and removes an addon on a product', function (): void {
    $addon = Addon::factory()->create([
        'organization_id' => $this->provider->id,
        'product_id' => $this->product->id,
    ]);

    $payload = [
        'name' => 'Extra mailbox',
        'slug' => 'extra-mailbox',
        'description' => 'One more mailbox.',
        'status' => CatalogStatus::Active->value,
        'position' => 0,
    ];

    $this->actingAs($this->staff, 'staff')
        ->put('/admin/catalog/products/'.$this->product->id.'/addons/'.$addon->id, $payload)
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    expect($addon->fresh()->name)->toBe('Extra mailbox');

    // Again, because the slug uniqueness is scoped to the product and has to
    // ignore this addon.
    $this->actingAs($this->staff, 'staff')
        ->put('/admin/catalog/products/'.$this->product->id.'/addons/'.$addon->id, [
            ...$payload,
            'name' => 'Extra mailbox (10 GB)',
        ])
        ->assertSessionHasNoErrors();

    $this->actingAs($this->staff, 'staff')
        ->delete('/admin/catalog/products/'.$this->product->id.'/addons/'.$addon->id)
        ->assertRedirect();

    expect(Addon::query()->whereKey($addon->id)->exists())->toBeFalse();
});

it('edits and removes an option group on a product', function (): void {
    $group = OptionGroup::factory()->create([
        'organization_id' => $this->provider->id,
        'product_id' => $this->product->id,
    ]);

    $payload = [
        'name' => 'Datacenter',
        'key' => 'datacenter',
        'type' => OptionType::Select->value,
        'description' => 'Where it runs.',
        'is_required' => true,
        'min_quantity' => 0,
        'max_quantity' => null,
        'position' => 0,
        // `present`, not `sometimes`: a group saved with no options is a
        // deliberate empty list, and the difference between "none" and "do not
        // touch them" is the one the form has to make explicit.
        'options' => [
            ['label' => 'Amsterdam', 'value' => 'ams', 'is_default' => true, 'position' => 0],
            ['label' => 'Frankfurt', 'value' => 'fra', 'is_default' => false, 'position' => 1],
        ],
    ];

    $this->actingAs($this->staff, 'staff')
        ->put('/admin/catalog/products/'.$this->product->id.'/options/'.$group->id, $payload)
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    expect($group->fresh()->key)->toBe('datacenter')
        ->and($group->fresh()->is_required)->toBeTrue()
        ->and($group->fresh()->options()->count())->toBe(2);

    $this->actingAs($this->staff, 'staff')
        ->put('/admin/catalog/products/'.$this->product->id.'/options/'.$group->id, $payload)
        ->assertSessionHasNoErrors();

    $this->actingAs($this->staff, 'staff')
        ->delete('/admin/catalog/products/'.$this->product->id.'/options/'.$group->id)
        ->assertRedirect();

    expect(OptionGroup::query()->whereKey($group->id)->exists())->toBeFalse();
});

it('edits a currency', function (): void {
    $currency = CurrencyRecord::factory()->create([
        'organization_id' => $this->provider->id,
        'code' => 'EUR',
    ]);

    $this->actingAs($this->staff, 'staff')
        ->put('/admin/catalog/currencies/'.$currency->id, [
            'code' => $currency->code,
            'name' => 'Euro (updated)',
            'symbol' => '€',
            // A decimal string, which is what the column keeps and what the
            // form sends: parsing it as a float here would lose precision.
            'rate' => '1.00000000',
            'is_base' => true,
            'is_active' => true,
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    expect($currency->fresh()->name)->toBe('Euro (updated)')
        ->and($currency->fresh()->is_active)->toBeTrue();
});

it('refuses a catalog write to somebody who may only look', function (): void {
    $reader = StaffUser::factory()->create(['organization_id' => $this->provider->id]);

    $this->actingAs($reader, 'staff')
        ->delete('/admin/catalog/products/'.$this->product->id)
        ->assertForbidden();

    expect(Product::query()->whereKey($this->product->id)->exists())->toBeTrue();
});
