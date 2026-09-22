<?php

declare(strict_types=1);

use App\Domain\Catalog\BillingCycle;
use App\Infrastructure\Catalog\Models\Product;
use App\Infrastructure\Catalog\Models\ProductGroup;
use App\Infrastructure\Catalog\Models\ProductPrice;
use App\Infrastructure\Organizations\Models\Organization;
use App\Infrastructure\Shared\Models\CurrencyRecord;
use Database\Seeders\ProviderOrganizationSeeder;

beforeEach(function (): void {
    $this->withoutVite();

    $this->seed(ProviderOrganizationSeeder::class);
    $this->provider = Organization::query()->withoutGlobalScope('organization')->whereNull('parent_id')->sole();

    CurrencyRecord::factory()->forOrganization($this->provider->id)->base()->code('EUR', 'Euro')->create();
});

function listedProduct(string $name, string $currency = 'EUR', int $minor = 999): Product
{
    /** @var Organization $provider */
    $provider = Organization::query()->withoutGlobalScope('organization')->whereNull('parent_id')->sole();

    $group = ProductGroup::factory()->forOrganization($provider->id)->create(['name' => 'Shared Hosting']);
    $product = Product::factory()->inGroup($group)->create(['name' => $name]);

    ProductPrice::factory()
        ->forProduct($product)
        ->cycle(BillingCycle::Monthly)
        ->currency($currency)
        ->amounts($minor)
        ->create();

    return $product;
}

it('says an installation has nothing for sale yet', function (): void {
    $this->get('/')
        ->assertOk()
        ->assertSee('noindex', false)
        ->assertSee(__('storefront.next_steps_title'), false);
});

it('points at the catalog once something is for sale', function (): void {
    listedProduct('Starter Plan');

    $this->get('/')
        ->assertOk()
        ->assertSee(__('storefront.plans'), false)
        ->assertDontSee('noindex', false);
});

it('lists products with a starting price', function (): void {
    listedProduct('Starter Plan');

    $this->get('/store')
        ->assertOk()
        ->assertSee('Starter Plan')
        ->assertSee('Shared Hosting');
});

it('leaves out a product that is not priced in the shown currency', function (): void {
    CurrencyRecord::factory()->forOrganization($this->provider->id)->code('USD', 'US Dollar', '1.08000000')->create();

    listedProduct('Dollar Only', 'USD');

    $this->get('/store')
        ->assertOk()
        ->assertDontSee('Dollar Only');
});

it('shows the product once the visitor switches to its currency', function (): void {
    CurrencyRecord::factory()->forOrganization($this->provider->id)->code('USD', 'US Dollar', '1.08000000')->create();

    listedProduct('Dollar Only', 'USD');

    $this->post('/store/currency', ['currency' => 'USD'])->assertRedirect();

    $this->get('/store')
        ->assertOk()
        ->assertSee('Dollar Only');
});

it('ignores a currency the installation does not trade in', function (): void {
    $this->post('/store/currency', ['currency' => 'XXX']);

    // Still the base currency, not a blank page and not an error.
    $this->get('/store')->assertOk();
});

it('serves a product page with its cycles', function (): void {
    $product = listedProduct('Starter Plan');

    ProductPrice::factory()
        ->forProduct($product)
        ->cycle(BillingCycle::Annually)
        ->currency('EUR')
        ->amounts(9990, 1500)
        ->create();

    $this->get("/store/{$product->slug}")
        ->assertOk()
        ->assertSee('Starter Plan')
        ->assertSee(__('catalog.cycles.monthly'), false)
        ->assertSee(__('catalog.cycles.annually'), false);
});

it('serves a hidden product by direct link but keeps it off the list', function (): void {
    $group = ProductGroup::factory()->forOrganization($this->provider->id)->create();
    $hidden = Product::factory()->inGroup($group)->hidden()->create(['name' => 'Unlisted Plan']);

    ProductPrice::factory()->forProduct($hidden)->currency('EUR')->create();

    $this->get('/store')->assertOk()->assertDontSee('Unlisted Plan');
    $this->get("/store/{$hidden->slug}")->assertOk()->assertSee('Unlisted Plan');
});

it('does not serve a retired product', function (): void {
    $group = ProductGroup::factory()->forOrganization($this->provider->id)->create();
    $retired = Product::factory()->inGroup($group)->retired()->create(['name' => 'Old Plan']);

    ProductPrice::factory()->forProduct($retired)->currency('EUR')->create();

    $this->get("/store/{$retired->slug}")->assertNotFound();
});

it('does not serve another organizations product', function (): void {
    $reseller = Organization::factory()->reseller($this->provider)->create();
    $group = ProductGroup::factory()->forOrganization($reseller->id)->create();
    $theirs = Product::factory()->inGroup($group)->create(['name' => 'Reseller Plan']);

    ProductPrice::factory()->forProduct($theirs)->currency('EUR')->create();

    // The provider's storefront shows the provider's catalog. A reseller's
    // own storefront arrives with white-labelling in Phase 11.
    $this->get('/store')->assertOk()->assertDontSee('Reseller Plan');
    $this->get("/store/{$theirs->slug}")->assertNotFound();
});

it('returns 404 for a product that does not exist', function (): void {
    $this->get('/store/nothing-here')->assertNotFound();
});
