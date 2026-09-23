<?php

declare(strict_types=1);

use App\Application\Access\SyncPermissions;
use App\Application\Ordering\AddToCart;
use App\Application\Ordering\AddToCartRequest;
use App\Application\Ordering\PriceCart;
use App\Application\Organizations\CreateReseller;
use App\Application\Organizations\Exceptions\ResellerRefused;
use App\Application\Organizations\ResellerAttributes;
use App\Application\Resellers\ResolveSellingPrice;
use App\Domain\Access\PermissionRegistry;
use App\Domain\Catalog\BillingCycle;
use App\Infrastructure\Catalog\Models\Product;
use App\Infrastructure\Crm\Models\Customer;
use App\Infrastructure\Identity\Models\StaffUser;
use App\Infrastructure\Ordering\Models\Cart;
use App\Infrastructure\Organizations\Models\Organization;
use App\Infrastructure\Resellers\Models\ResellerPrice;
use App\Infrastructure\Resellers\Models\ResellerProduct;
use App\Support\Organizations\OrganizationContext;
use Carbon\CarbonImmutable;
use Database\Factories\ProductPriceFactory;
use Database\Seeders\ProviderOrganizationSeeder;
use Database\Seeders\SystemRoleSeeder;
use Illuminate\Support\Str;

/**
 * What a reseller's customer pays.
 *
 * The rule under test everywhere here: **one place works it out**. A
 * storefront card showing the provider's number and a cart charging the
 * reseller's is the one pricing bug a customer always notices, so the
 * resolver is asked by the catalogue, the cart and the admin order form
 * alike — and these tests check they agree rather than checking each in
 * isolation.
 */
beforeEach(function (): void {
    $this->withoutVite();

    $this->seed(ProviderOrganizationSeeder::class);
    app(SyncPermissions::class)->handle(app(PermissionRegistry::class));
    $this->seed(SystemRoleSeeder::class);

    $this->product = Product::factory()->create(['requires_domain' => false]);

    ProductPriceFactory::new()
        ->forProduct($this->product)
        ->cycle(BillingCycle::Monthly)
        ->currency('EUR')
        ->amounts(2000, 500)
        ->create();

    $created = app(CreateReseller::class)->handle(new ResellerAttributes(
        name: 'Anatolia Hosting',
        ownerName: 'Reseller Owner',
        ownerEmail: 'owner@anatolia.test',
    ));

    $this->reseller = $created['organization'];
    $this->resellerOwner = $created['owner'];

    // A customer of the reseller, which is what makes the reseller the
    // seller for everything below.
    $this->customer = app(OrganizationContext::class)->runAs(
        $this->reseller->id,
        fn (): Customer => Customer::factory()->create([
            'organization_id' => app(OrganizationContext::class)->runAs(
                $this->reseller->id,
                fn (): string => Organization::query()->create([
                    'parent_id' => $this->reseller->id,
                    'type' => 'customer',
                    'name' => 'Reseller Client',
                    'slug' => 'reseller-client-'.Str::lower(Str::random(6)),
                    'is_active' => true,
                ])->id,
            ),
            'currency_code' => 'EUR',
        ]),
    );
});

function resolverFor(string $organizationId): ResolveSellingPrice
{
    return app(ResolveSellingPrice::class);
}

it('charges the provider price when the reseller set no markup', function (): void {
    ResellerProduct::factory()
        ->forReseller($this->reseller)
        ->forProduct($this->product)
        ->create();

    $price = app(ResolveSellingPrice::class)
        ->resolve($this->product, BillingCycle::Monthly, 'EUR', $this->customer->organization_id);

    expect($price->recurring->minorUnits)->toBe(2000)
        ->and($price->setup->minorUnits)->toBe(500)
        ->and($price->isMarkedUp())->toBeFalse();
});

it('applies the markup to the recurring price and the setup fee alike', function (): void {
    ResellerProduct::factory()
        ->forReseller($this->reseller)
        ->forProduct($this->product)
        ->withMargin('25.0000')
        ->create();

    $price = app(ResolveSellingPrice::class)
        ->resolve($this->product, BillingCycle::Monthly, 'EUR', $this->customer->organization_id);

    // A reseller whose recurring price carried a margin and whose setup fee
    // did not would be discounting the setup without having said so.
    expect($price->recurring->minorUnits)->toBe(2500)
        ->and($price->setup->minorUnits)->toBe(625)
        ->and($price->marginPercent)->toBe('25.0000');
});

/**
 * An operator who typed a number meant that number. It resolves, it does
 * not merge — the markup is not applied on top of an exact price.
 */
it('lets an exact reseller price beat the markup', function (): void {
    ResellerProduct::factory()
        ->forReseller($this->reseller)
        ->forProduct($this->product)
        ->withMargin('25.0000')
        ->create();

    ResellerPrice::factory()
        ->forReseller($this->reseller)
        ->forProduct($this->product)
        ->amounts(3300, 0)
        ->create(['billing_cycle' => BillingCycle::Monthly->value, 'currency_code' => 'EUR']);

    $price = app(ResolveSellingPrice::class)
        ->resolve($this->product, BillingCycle::Monthly, 'EUR', $this->customer->organization_id);

    expect($price->recurring->minorUnits)->toBe(3300)
        ->and($price->setup->minorUnits)->toBe(0)
        ->and($price->isMarkedUp())->toBeFalse();
});

it('marks nothing up for the provider own customers', function (): void {
    ResellerProduct::factory()
        ->forReseller($this->reseller)
        ->forProduct($this->product)
        ->withMargin('25.0000')
        ->create();

    $direct = Customer::factory()->create(['currency_code' => 'EUR']);

    $price = app(ResolveSellingPrice::class)
        ->resolve($this->product, BillingCycle::Monthly, 'EUR', $direct->organization_id);

    expect($price->recurring->minorUnits)->toBe(2000);
});

/**
 * Absence means not sold. A markup on nothing is nothing, so a cycle the
 * provider never priced stays unsold for the reseller too — otherwise a
 * product appears on a storefront and the cart then refuses it.
 */
it('invents no price for a cycle the provider does not sell', function (): void {
    ResellerProduct::factory()
        ->forReseller($this->reseller)
        ->forProduct($this->product)
        ->withMargin('25.0000')
        ->create();

    $price = app(ResolveSellingPrice::class)
        ->resolve($this->product, BillingCycle::Annually, 'EUR', $this->customer->organization_id);

    expect($price)->toBeNull();
});

it('charges the marked-up price in the cart, not the catalogue price', function (): void {
    ResellerProduct::factory()
        ->forReseller($this->reseller)
        ->forProduct($this->product)
        ->withMargin('50.0000')
        ->create();

    $organizations = app(OrganizationContext::class);

    $totals = $organizations->runAs($this->customer->organization_id, function (): object {
        $cart = Cart::query()->create([
            'organization_id' => $this->customer->organization_id,
            'customer_id' => $this->customer->id,
            'token' => Str::ulid().Str::lower(Str::random(14)),
            'currency_code' => 'EUR',
            'expires_at' => CarbonImmutable::now()->addHour(),
        ]);

        app(AddToCart::class)->handle($cart, new AddToCartRequest(
            productId: $this->product->id,
            cycle: BillingCycle::Monthly,
        ));

        return app(PriceCart::class)->handle($cart->fresh());
    });

    // 2000 + 50% recurring, 500 + 50% setup.
    expect($totals->subtotal->minorUnits)->toBe(3000)
        ->and($totals->setup->minorUnits)->toBe(750);
});

it('creates the reseller with an owner who can sign in to the panel', function (): void {
    expect($this->reseller->type->value)->toBe('reseller')
        ->and($this->resellerOwner->organization_id)->toBe($this->reseller->id)
        // Administrator, not super-admin: a reseller bypassing permission
        // checks would be a reseller outside the boundary that makes resale
        // safe.
        ->and($this->resellerOwner->fresh()->roles()->pluck('slug')->all())
        ->toBe(['administrator']);
});

it('refuses a reseller whose owner already has an account', function (): void {
    StaffUser::factory()->create(['email' => 'taken@example.test']);

    app(CreateReseller::class)->handle(new ResellerAttributes(
        name: 'Second',
        ownerName: 'Somebody',
        ownerEmail: 'taken@example.test',
    ));
})->throws(ResellerRefused::class);
