<?php

declare(strict_types=1);

use App\Domain\Catalog\BillingCycle;
use App\Domain\Catalog\CatalogStatus;
use App\Domain\Catalog\OptionType;
use App\Domain\Catalog\ProductType;
use App\Domain\Shared\Money;
use App\Infrastructure\Catalog\Models\Addon;
use App\Infrastructure\Catalog\Models\Option;
use App\Infrastructure\Catalog\Models\OptionGroup;
use App\Infrastructure\Catalog\Models\Product;
use App\Infrastructure\Catalog\Models\ProductGroup;
use App\Infrastructure\Catalog\Models\ProductPrice;
use App\Infrastructure\Organizations\Models\Organization;
use App\Support\Organizations\OrganizationContext;

beforeEach(function (): void {
    $this->provider = Organization::factory()->provider()->create(['name' => 'InfraCMS']);
    $this->reseller = Organization::factory()->reseller($this->provider)->create(['name' => 'Reseller A']);
    $this->context = app(OrganizationContext::class);
});

it('round-trips money through the two-column cast', function (): void {
    $price = ProductPrice::factory()->amounts(1999, 500)->currency('USD')->create();

    $fresh = ProductPrice::query()->withoutGlobalScope('organization')->findOrFail($price->id);

    expect($fresh->recurring)->toBeInstanceOf(Money::class)
        ->and($fresh->recurring?->minorUnits)->toBe(1999)
        ->and($fresh->recurring?->currency->code)->toBe('USD')
        ->and($fresh->setup?->minorUnits)->toBe(500)
        ->and($fresh->recurring?->toDecimalString())->toBe('19.99');
});

it('writes the currency alongside the amount when a money object is assigned', function (): void {
    $price = ProductPrice::factory()->create();

    $price->recurring = Money::ofDecimal('49.50', 'GBP');
    $price->save();

    expect($price->fresh()?->getAttribute('recurring_minor'))->toBe(4950)
        ->and($price->fresh()?->currency_code)->toBe('GBP');
});

it('refuses anything but a money object on a money attribute', function (): void {
    $price = ProductPrice::factory()->create();

    $price->recurring = 1999;
})->throws(InvalidArgumentException::class);

it('reads a zero amount back as zero money, not as absent', function (): void {
    // Absence is carried by the missing row, not by a null column: a price
    // row that exists with zero in it means "free", which is a different
    // statement from "not sold on this cycle".
    $price = ProductPrice::factory()->amounts(999, 0)->create();

    expect($price->fresh()?->setup)->toBeInstanceOf(Money::class)
        ->and($price->fresh()?->setup?->isZero())->toBeTrue();
});

it('derives a slug from the name', function (): void {
    $group = ProductGroup::factory()->create(['name' => 'Shared Hosting Plans', 'slug' => '']);

    expect($group->slug)->toBe('shared-hosting-plans');
});

it('sets the domain requirement from the product type', function (): void {
    $hosting = Product::factory()->ofType(ProductType::SharedHosting)->create();
    $license = Product::factory()->ofType(ProductType::License)->create();

    expect($hosting->requires_domain)->toBeTrue()
        ->and($license->requires_domain)->toBeFalse();
});

it('lets an operator override the domain requirement', function (): void {
    $vps = Product::factory()->ofType(ProductType::Vps)->create(['requires_domain' => true]);

    expect($vps->fresh()?->requires_domain)->toBeTrue();
});

it('finds a price for one cycle in one currency', function (): void {
    $product = Product::factory()->create();

    ProductPrice::factory()->forProduct($product)->cycle(BillingCycle::Monthly)->currency('EUR')->amounts(999)->create();
    ProductPrice::factory()->forProduct($product)->cycle(BillingCycle::Annually)->currency('EUR')->amounts(9990)->create();
    ProductPrice::factory()->forProduct($product)->cycle(BillingCycle::Monthly)->currency('USD')->amounts(1099)->create();

    $product->load('prices');

    expect($product->recurringFor(BillingCycle::Monthly, 'EUR')?->minorUnits)->toBe(999)
        ->and($product->recurringFor(BillingCycle::Annually, 'EUR')?->minorUnits)->toBe(9990)
        ->and($product->recurringFor(BillingCycle::Monthly, 'usd')?->minorUnits)->toBe(1099)
        ->and($product->recurringFor(BillingCycle::Annually, 'USD'))->toBeNull();
});

it('reports which currencies a product is sold in', function (): void {
    $product = Product::factory()->create();

    ProductPrice::factory()->forProduct($product)->currency('EUR')->create();

    $product->load('prices');

    expect($product->isSellableIn('EUR'))->toBeTrue()
        ->and($product->isSellableIn('TRY'))->toBeFalse();
});

it('lists available cycles shortest first, per currency', function (): void {
    $product = Product::factory()->create();

    foreach ([BillingCycle::Annually, BillingCycle::OneTime, BillingCycle::Monthly] as $cycle) {
        ProductPrice::factory()->forProduct($product)->cycle($cycle)->currency('EUR')->create();
    }

    ProductPrice::factory()->forProduct($product)->cycle(BillingCycle::Annually)->currency('USD')->create();

    $product->load('prices');

    expect($product->availableCycles('EUR'))
        ->toBe([BillingCycle::Monthly, BillingCycle::Annually, BillingCycle::OneTime])
        ->and($product->availableCycles('USD'))->toBe([BillingCycle::Annually]);
});

it('keeps a hidden product orderable but unlisted', function (): void {
    $active = Product::factory()->create();
    $hidden = Product::factory()->hidden()->create();
    $retired = Product::factory()->retired()->create();

    expect($active->status->isListed())->toBeTrue()
        ->and($hidden->status->isListed())->toBeFalse()
        ->and($hidden->isOrderable())->toBeTrue()
        ->and($retired->isOrderable())->toBeFalse()
        ->and(Product::query()->listed()->pluck('id')->all())->toBe([$active->id])
        ->and(Product::query()->orderable()->pluck('id')->sort()->values()->all())
        ->toBe(collect([$active->id, $hidden->id])->sort()->values()->all());
});

it('treats a zero stock as sold out and a null stock as unlimited', function (): void {
    $unlimited = Product::factory()->create();
    $soldOut = Product::factory()->soldOut()->create();

    expect($unlimited->isOrderable())->toBeTrue()
        ->and($unlimited->isSoldOut())->toBeFalse()
        ->and($soldOut->isOrderable())->toBeFalse()
        ->and($soldOut->isSoldOut())->toBeTrue();
});

it('prices an option as a signed delta', function (): void {
    $product = Product::factory()->create();

    $group = OptionGroup::factory()->forProduct($product)->create([
        'name' => 'Control panel',
        'type' => OptionType::Select->value,
    ]);

    $none = Option::factory()->inGroup($group)->create(['label' => 'None', 'value' => 'none']);
    $cpanel = Option::factory()->inGroup($group)->create(['label' => 'cPanel', 'value' => 'cpanel']);

    $none->prices()->create([
        'organization_id' => $none->organization_id,
        'billing_cycle' => BillingCycle::Monthly->value,
        'currency_code' => 'EUR',
        'recurring_minor' => -300,
        'setup_minor' => 0,
    ]);

    $cpanel->prices()->create([
        'organization_id' => $cpanel->organization_id,
        'billing_cycle' => BillingCycle::Monthly->value,
        'currency_code' => 'EUR',
        'recurring_minor' => 500,
        'setup_minor' => 0,
    ]);

    $none->load('prices');
    $cpanel->load('prices');

    $base = Money::ofMinor(999, 'EUR');

    expect($none->recurringFor(BillingCycle::Monthly, 'EUR')?->isNegative())->toBeTrue()
        ->and($base->plus($none->recurringFor(BillingCycle::Monthly, 'EUR'))->minorUnits)->toBe(699)
        ->and($base->plus($cpanel->recurringFor(BillingCycle::Monthly, 'EUR'))->minorUnits)->toBe(1499);
});

it('scopes every catalog record to its organization', function (): void {
    $mine = ProductGroup::factory()->create(['organization_id' => $this->provider->id]);
    $theirs = ProductGroup::factory()->create(['organization_id' => $this->reseller->id]);

    $this->context->runAs($this->reseller->id, function () use ($mine, $theirs): void {
        $visible = ProductGroup::query()->pluck('id')->all();

        expect($visible)->toContain($theirs->id)
            ->and($visible)->not->toContain($mine->id);
    });
});

it('hides catalog records belonging to another organization', function (): void {
    $product = Product::factory()->create(['organization_id' => $this->provider->id]);
    $group = OptionGroup::factory()->forProduct($product)->create();
    $option = Option::factory()->inGroup($group)->create();
    $addon = Addon::factory()->forProduct($product)->create();

    $this->context->runAs($this->reseller->id, function (): void {
        expect(Product::query()->count())->toBe(0)
            ->and(OptionGroup::query()->count())->toBe(0)
            ->and(Option::query()->count())->toBe(0)
            ->and(Addon::query()->count())->toBe(0);
    });

    expect($addon->status)->toBeInstanceOf(CatalogStatus::class)
        ->and($option->organization_id)->toBe($this->provider->id)
        ->and($addon->organization_id)->toBe($this->provider->id);
});
