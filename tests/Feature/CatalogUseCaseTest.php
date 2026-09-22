<?php

declare(strict_types=1);

use App\Application\Catalog\AddonAttributes;
use App\Application\Catalog\CurrencyAttributes;
use App\Application\Catalog\DeleteCurrency;
use App\Application\Catalog\DeleteProductGroup;
use App\Application\Catalog\Exceptions\CurrencyInUse;
use App\Application\Catalog\Exceptions\GroupNotEmpty;
use App\Application\Catalog\Exceptions\GroupOutsideBoundary;
use App\Application\Catalog\Exceptions\InvalidPriceMatrix;
use App\Application\Catalog\OptionAttributes;
use App\Application\Catalog\OptionGroupAttributes;
use App\Application\Catalog\PriceMatrixEntry;
use App\Application\Catalog\ProductAttributes;
use App\Application\Catalog\ProductGroupAttributes;
use App\Application\Catalog\SaveAddon;
use App\Application\Catalog\SaveCurrency;
use App\Application\Catalog\SaveOptionGroup;
use App\Application\Catalog\SavePriceMatrix;
use App\Application\Catalog\SaveProduct;
use App\Application\Catalog\SaveProductGroup;
use App\Application\Catalog\StorefrontCatalog;
use App\Domain\Catalog\BillingCycle;
use App\Domain\Catalog\CatalogStatus;
use App\Domain\Catalog\OptionType;
use App\Domain\Catalog\ProductType;
use App\Domain\Shared\Exceptions\CurrencyMismatch;
use App\Domain\Shared\Money;
use App\Infrastructure\Catalog\Models\Product;
use App\Infrastructure\Catalog\Models\ProductGroup;
use App\Infrastructure\Catalog\Models\ProductPrice;
use App\Infrastructure\Organizations\Models\Organization;
use App\Infrastructure\Shared\Models\CurrencyRecord;
use App\Infrastructure\Shared\Models\ExchangeRateSnapshot;

beforeEach(function (): void {
    $this->provider = Organization::factory()->provider()->create(['name' => 'InfraCMS']);
    $this->reseller = Organization::factory()->reseller($this->provider)->create(['name' => 'Reseller A']);
    $this->audit = $this->fakeAudit();
});

it('creates a product group and derives its slug', function (): void {
    $group = app(SaveProductGroup::class)->handle(
        $this->provider->id,
        new ProductGroupAttributes(name: 'Shared Hosting', description: 'Small sites.'),
    );

    expect($group->slug)->toBe('shared-hosting')
        ->and($group->organization_id)->toBe($this->provider->id)
        ->and($this->audit->actions())->toContain('catalog.group.created');
});

it('records what changed when a group is edited', function (): void {
    $group = ProductGroup::factory()->create(['name' => 'Old name']);

    app(SaveProductGroup::class)->handle(
        $group->organization_id,
        new ProductGroupAttributes(name: 'New name', slug: $group->slug, status: CatalogStatus::Hidden),
        $group,
    );

    $entry = $this->audit->last();

    expect($entry?->action)->toBe('catalog.group.updated')
        ->and($entry?->changes['name']['from'] ?? null)->toBe('Old name')
        ->and($entry?->changes['name']['to'] ?? null)->toBe('New name');
});

it('refuses to delete a group that still holds products', function (): void {
    $group = ProductGroup::factory()->create();
    Product::factory()->inGroup($group)->create();

    app(DeleteProductGroup::class)->handle($group);
})->throws(GroupNotEmpty::class);

it('deletes an empty group', function (): void {
    $group = ProductGroup::factory()->create();

    app(DeleteProductGroup::class)->handle($group);

    expect(ProductGroup::query()->whereKey($group->id)->exists())->toBeFalse()
        ->and($this->audit->actions())->toContain('catalog.group.deleted');
});

it('refuses to file a product under another organizations group', function (): void {
    $theirs = ProductGroup::factory()->create(['organization_id' => $this->reseller->id]);

    app(SaveProduct::class)->handle(
        $this->provider->id,
        new ProductAttributes(
            productGroupId: $theirs->id,
            name: 'Starter',
            type: ProductType::SharedHosting,
        ),
    );
})->throws(GroupOutsideBoundary::class);

it('creates a product with the domain rule its type implies', function (): void {
    $group = ProductGroup::factory()->create(['organization_id' => $this->provider->id]);

    $product = app(SaveProduct::class)->handle(
        $this->provider->id,
        new ProductAttributes(
            productGroupId: $group->id,
            name: 'Starter Plan',
            type: ProductType::SharedHosting,
            features: ['10 GB SSD', 'Free SSL'],
        ),
    );

    expect($product->slug)->toBe('starter-plan')
        ->and($product->requires_domain)->toBeTrue()
        ->and($product->features)->toBe(['10 GB SSD', 'Free SSL'])
        ->and($this->audit->actions())->toContain('catalog.product.created');
});

it('writes a price matrix and audits it separately from the product', function (): void {
    $product = Product::factory()->create();

    app(SavePriceMatrix::class)->handle($product, [
        PriceMatrixEntry::of(BillingCycle::Monthly, 999, 0, 'EUR'),
        PriceMatrixEntry::of(BillingCycle::Annually, 9990, 0, 'EUR'),
        PriceMatrixEntry::of(BillingCycle::Monthly, 1099, 500, 'USD'),
    ]);

    $product->load('prices');

    expect($product->prices)->toHaveCount(3)
        ->and($product->recurringFor(BillingCycle::Monthly, 'EUR')?->minorUnits)->toBe(999)
        ->and($product->setupFor(BillingCycle::Monthly, 'USD')?->minorUnits)->toBe(500)
        ->and($this->audit->actions())->toContain('catalog.pricing.updated');
});

it('removes a cell the operator left out of the matrix', function (): void {
    $product = Product::factory()->create();

    app(SavePriceMatrix::class)->handle($product, [
        PriceMatrixEntry::of(BillingCycle::Monthly, 999, 0, 'EUR'),
        PriceMatrixEntry::of(BillingCycle::Annually, 9990, 0, 'EUR'),
    ]);

    app(SavePriceMatrix::class)->handle($product->fresh() ?? $product, [
        PriceMatrixEntry::of(BillingCycle::Monthly, 999, 0, 'EUR'),
    ]);

    $product->load('prices');

    expect($product->prices)->toHaveCount(1)
        ->and($product->recurringFor(BillingCycle::Annually, 'EUR'))->toBeNull();
});

it('updates a cell in place rather than stacking rows', function (): void {
    $product = Product::factory()->create();

    app(SavePriceMatrix::class)->handle($product, [PriceMatrixEntry::of(BillingCycle::Monthly, 999, 0, 'EUR')]);
    app(SavePriceMatrix::class)->handle($product->fresh() ?? $product, [PriceMatrixEntry::of(BillingCycle::Monthly, 1299, 0, 'EUR')]);

    $product->load('prices');

    expect($product->prices)->toHaveCount(1)
        ->and($product->recurringFor(BillingCycle::Monthly, 'EUR')?->toDecimalString())->toBe('12.99');
});

it('records the old and new price in the pricing audit entry', function (): void {
    $product = Product::factory()->create();

    app(SavePriceMatrix::class)->handle($product, [PriceMatrixEntry::of(BillingCycle::Monthly, 999, 0, 'EUR')]);
    app(SavePriceMatrix::class)->handle($product->fresh() ?? $product, [PriceMatrixEntry::of(BillingCycle::Monthly, 1299, 0, 'EUR')]);

    $entry = $this->audit->last();

    expect($entry?->action)->toBe('catalog.pricing.updated')
        ->and($entry?->changes['monthly:EUR']['from'] ?? null)->toBe('9.99 + 0.00 setup')
        ->and($entry?->changes['monthly:EUR']['to'] ?? null)->toBe('12.99 + 0.00 setup');
});

it('writes nothing to the audit trail when a price save changes nothing', function (): void {
    $product = Product::factory()->create();

    app(SavePriceMatrix::class)->handle($product, [PriceMatrixEntry::of(BillingCycle::Monthly, 999, 0, 'EUR')]);

    $count = count($this->audit->actions());

    app(SavePriceMatrix::class)->handle($product->fresh() ?? $product, [PriceMatrixEntry::of(BillingCycle::Monthly, 999, 0, 'EUR')]);

    expect($this->audit->actions())->toHaveCount($count);
});

it('refuses a matrix that names the same cell twice', function (): void {
    app(SavePriceMatrix::class)->handle(Product::factory()->create(), [
        PriceMatrixEntry::of(BillingCycle::Monthly, 999, 0, 'EUR'),
        PriceMatrixEntry::of(BillingCycle::Monthly, 1299, 0, 'EUR'),
    ]);
})->throws(InvalidPriceMatrix::class);

it('refuses a cell whose setup fee is in another currency', function (): void {
    new PriceMatrixEntry(BillingCycle::Monthly, Money::ofMinor(999, 'EUR'), Money::ofMinor(500, 'USD'));
})->throws(CurrencyMismatch::class);

it('saves an option group with its choices and their price deltas', function (): void {
    $product = Product::factory()->create();

    $group = app(SaveOptionGroup::class)->handle($product, new OptionGroupAttributes(
        name: 'Control panel',
        key: 'control_panel',
        type: OptionType::Select,
        isRequired: true,
        options: [
            new OptionAttributes(label: 'None', value: 'none', isDefault: true, prices: [
                PriceMatrixEntry::of(BillingCycle::Monthly, -300, 0, 'EUR'),
            ]),
            new OptionAttributes(label: 'cPanel', value: 'cpanel', position: 1, prices: [
                PriceMatrixEntry::of(BillingCycle::Monthly, 500, 0, 'EUR'),
            ]),
        ],
    ));

    $options = $group->options()->orderBy('position')->with('prices')->get();

    expect($options)->toHaveCount(2)
        ->and($options[0]->is_default)->toBeTrue()
        ->and($options[0]->recurringFor(BillingCycle::Monthly, 'EUR')?->minorUnits)->toBe(-300)
        ->and($options[1]->recurringFor(BillingCycle::Monthly, 'EUR')?->minorUnits)->toBe(500)
        ->and($this->audit->actions())->toContain('catalog.option_group.created');
});

it('allows only one default choice in an option group', function (): void {
    $product = Product::factory()->create();

    $group = app(SaveOptionGroup::class)->handle($product, new OptionGroupAttributes(
        name: 'Backups',
        key: 'backups',
        type: OptionType::Select,
        options: [
            new OptionAttributes(label: 'Daily', value: 'daily', isDefault: true),
            new OptionAttributes(label: 'Weekly', value: 'weekly', isDefault: true, position: 1),
        ],
    ));

    expect($group->options()->where('is_default', true)->count())->toBe(1);
});

it('removes a choice the operator deleted from the group', function (): void {
    $product = Product::factory()->create();
    $saveGroup = app(SaveOptionGroup::class);

    $group = $saveGroup->handle($product, new OptionGroupAttributes(
        name: 'Backups',
        key: 'backups',
        type: OptionType::Select,
        options: [
            new OptionAttributes(label: 'Daily', value: 'daily'),
            new OptionAttributes(label: 'Weekly', value: 'weekly', position: 1),
        ],
    ));

    $saveGroup->handle($product, new OptionGroupAttributes(
        name: 'Backups',
        key: 'backups',
        type: OptionType::Select,
        options: [new OptionAttributes(label: 'Daily', value: 'daily')],
    ), $group);

    expect($group->options()->pluck('value')->all())->toBe(['daily']);
});

it('saves an addon under a product', function (): void {
    $product = Product::factory()->create();

    $addon = app(SaveAddon::class)->handle($product, new AddonAttributes(name: 'Dedicated IP'));

    expect($addon->slug)->toBe('dedicated-ip')
        ->and($addon->product_id)->toBe($product->id)
        ->and($addon->organization_id)->toBe($product->organization_id)
        ->and($this->audit->actions())->toContain('catalog.addon.created');
});

it('appends a rate snapshot every time a currency is saved', function (): void {
    $record = app(SaveCurrency::class)->handle(
        $this->provider->id,
        new CurrencyAttributes(code: 'TRY', name: 'Turkish Lira', rate: '42.00000000'),
    );

    app(SaveCurrency::class)->handle(
        $this->provider->id,
        new CurrencyAttributes(code: 'TRY', name: 'Turkish Lira', rate: '43.50000000'),
        $record,
    );

    expect(ExchangeRateSnapshot::query()->where('currency_id', $record->id)->pluck('rate')->all())
        ->toBe(['42.00000000', '43.50000000'])
        ->and($record->fresh()?->rate)->toBe('43.50000000');
});

it('snapshots the rate the base currency actually has', function (): void {
    // The model forces a base rate of one, so a snapshot taken from the
    // submitted value would record a rate that was never in effect.
    $record = app(SaveCurrency::class)->handle(
        $this->provider->id,
        new CurrencyAttributes(code: 'EUR', name: 'Euro', rate: '1.35000000', isBase: true),
    );

    expect(ExchangeRateSnapshot::query()->where('currency_id', $record->id)->value('rate'))->toBe('1.00000000');
});

it('refuses to delete the base currency', function (): void {
    $record = CurrencyRecord::factory()->forOrganization($this->provider)->base()->code('EUR', 'Euro')->create();

    app(DeleteCurrency::class)->handle($record);
})->throws(CurrencyInUse::class);

it('refuses to delete a currency that still has prices', function (): void {
    $record = CurrencyRecord::factory()->forOrganization($this->provider)->code('USD', 'US Dollar')->create();
    ProductPrice::factory()->currency('USD')->create();

    app(DeleteCurrency::class)->handle($record);
})->throws(CurrencyInUse::class);

it('lists only groups whose products are sold in the requested currency', function (): void {
    $euroGroup = ProductGroup::factory()->create(['organization_id' => $this->provider->id, 'name' => 'Euro plans']);
    $dollarGroup = ProductGroup::factory()->create(['organization_id' => $this->provider->id, 'name' => 'Dollar plans']);

    $euroProduct = Product::factory()->inGroup($euroGroup)->create();
    $dollarProduct = Product::factory()->inGroup($dollarGroup)->create();

    ProductPrice::factory()->forProduct($euroProduct)->currency('EUR')->create();
    ProductPrice::factory()->forProduct($dollarProduct)->currency('USD')->create();

    $groups = app(StorefrontCatalog::class)->groups('EUR');

    expect($groups)->toHaveCount(1)
        ->and($groups->first()?->id)->toBe($euroGroup->id);
});

it('keeps a hidden product off the listing but reachable by slug', function (): void {
    $group = ProductGroup::factory()->create(['organization_id' => $this->provider->id]);
    $hidden = Product::factory()->inGroup($group)->hidden()->create(['name' => 'Secret Plan']);
    ProductPrice::factory()->forProduct($hidden)->currency('EUR')->create();

    $storefront = app(StorefrontCatalog::class);

    expect($storefront->groups('EUR'))->toHaveCount(0)
        ->and($storefront->product($hidden->slug, 'EUR')?->id)->toBe($hidden->id);
});

it('does not serve a product in a currency it is not priced in', function (): void {
    $product = Product::factory()->create(['organization_id' => $this->provider->id]);
    ProductPrice::factory()->forProduct($product)->currency('EUR')->create();

    expect(app(StorefrontCatalog::class)->product($product->slug, 'USD'))->toBeNull();
});

it('picks the cheapest monthly equivalent as the starting price', function (): void {
    $product = Product::factory()->create();

    // 9.99/mo against 99.90/yr: the annual plan is cheaper per month, and a
    // naive minimum would have shown the monthly one.
    ProductPrice::factory()->forProduct($product)->cycle(BillingCycle::Monthly)->currency('EUR')->amounts(999)->create();
    ProductPrice::factory()->forProduct($product)->cycle(BillingCycle::Annually)->currency('EUR')->amounts(9990)->create();

    $product->load('prices');

    $starting = app(StorefrontCatalog::class)->startingPrice($product, 'EUR');

    expect($starting['cycle'] ?? null)->toBe(BillingCycle::Annually)
        ->and($starting['money']->toDecimalString() ?? null)->toBe('99.90');
});

it('falls back to a one-time price when nothing recurs', function (): void {
    $product = Product::factory()->ofType(ProductType::License)->create();

    ProductPrice::factory()->forProduct($product)->cycle(BillingCycle::OneTime)->currency('EUR')->amounts(4900)->create();

    $product->load('prices');

    $starting = app(StorefrontCatalog::class)->startingPrice($product, 'EUR');

    expect($starting['cycle'] ?? null)->toBe(BillingCycle::OneTime)
        ->and($starting['money']->toDecimalString() ?? null)->toBe('49.00');
});
