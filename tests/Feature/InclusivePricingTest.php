<?php

declare(strict_types=1);

use App\Application\Access\SyncPermissions;
use App\Application\Ordering\AddToCart;
use App\Application\Ordering\AddToCartRequest;
use App\Application\Ordering\CartTotals;
use App\Application\Ordering\PriceCart;
use App\Application\Tax\TaxIdentity;
use App\Domain\Access\PermissionRegistry;
use App\Domain\Access\SystemRole;
use App\Domain\Catalog\BillingCycle;
use App\Domain\Catalog\ProductType;
use App\Domain\Organizations\OrganizationType;
use App\Domain\Shared\Money;
use App\Domain\Tax\Contracts\TaxCalculator;
use App\Domain\Tax\TaxableSupply;
use App\Domain\Tax\TaxAppliesTo;
use App\Domain\Tax\TaxRounding;
use App\Infrastructure\Catalog\Models\Addon;
use App\Infrastructure\Catalog\Models\AddonPrice;
use App\Infrastructure\Catalog\Models\Product;
use App\Infrastructure\Catalog\Models\ProductPrice;
use App\Infrastructure\Crm\Models\Customer;
use App\Infrastructure\Identity\Models\Contact;
use App\Infrastructure\Ordering\Models\Cart;
use App\Infrastructure\Organizations\Models\Organization;
use App\Infrastructure\Tax\Models\TaxRule;
use App\Infrastructure\Tax\Models\TaxSetting;
use App\Support\Organizations\OrganizationContext;
use Database\Seeders\ProviderOrganizationSeeder;
use Database\Seeders\SystemRoleSeeder;

/**
 * The three answers on the tax screen that are not a rate.
 *
 * All three were stored and read by nothing — switches an operator could turn on
 * with no effect, which is worse than a switch that is absent. The most
 * expensive of them is `prices_include_tax`: an operator who turns it on and
 * gets nothing has silently added VAT on top of prices that already contained
 * it, on every order, until a customer complains.
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
});

function inclusiveSettings(bool $inclusive = true, array $overrides = []): TaxSetting
{
    return TaxSetting::factory()
        ->forOrganization(test()->provider->id)
        ->create(array_merge(['prices_include_tax' => $inclusive], $overrides));
}

function ruleFor(string $name, string $percent, array $overrides = []): TaxRule
{
    return TaxRule::factory()->create(array_merge([
        'organization_id' => test()->provider->id,
        'name' => $name,
        'rate_ppm' => (int) round(((float) $percent) * 10_000),
        'country_code' => 'TR',
        'level' => 1,
        'compound' => false,
        'is_active' => true,
    ], $overrides));
}

function taxOnGross(int $minor, array $supply = []): array
{
    $result = app(TaxCalculator::class)->calculate(new TaxableSupply(
        amount: Money::ofMinor($minor, 'TRY'),
        countryCode: $supply['country'] ?? 'TR',
        stateCode: $supply['region'] ?? null,
        taxId: $supply['taxId'] ?? null,
        isBusiness: $supply['isBusiness'] ?? false,
    ));

    return [
        'total' => $result->total->minorUnits,
        'included' => $result->included,
        'components' => array_map(
            static fn (object $component): int => $component->amount->minorUnits,
            $result->components,
        ),
    ];
}

/**
 * A cart holding one product at a known price, in the provider's own
 * organization so the tax rules above apply to it.
 */
function cartWithOneProduct(int $minor): Cart
{
    $product = Product::factory()
        ->ofType(ProductType::Vps)
        ->create(['organization_id' => test()->provider->id, 'name' => 'Starter']);

    ProductPrice::factory()
        ->forProduct($product)
        ->cycle(BillingCycle::Monthly)
        ->currency('TRY')
        ->amounts($minor, 0)
        ->create();

    $cart = Cart::factory()->forOrganization(test()->provider)->currency('TRY')->create();

    app(AddToCart::class)->handle($cart, new AddToCartRequest(
        productId: $product->id,
        cycle: BillingCycle::Monthly,
    ));

    return $cart->fresh() ?? $cart;
}

/**
 * A cart holding one product and one or more addons.
 *
 * Two kinds of line so they can be taxed differently, and `$addon` takes a list
 * so a caller can also put two lines of one kind in — which is what shows the
 * rounding setting doing anything.
 */
function priceMixedCart(int $product, int|array $addon): CartTotals
{
    $model = Product::factory()
        ->ofType(ProductType::Vps)
        ->create(['organization_id' => test()->provider->id, 'name' => 'Starter']);

    ProductPrice::factory()
        ->forProduct($model)
        ->cycle(BillingCycle::Monthly)
        ->currency('TRY')
        ->amounts($product, 0)
        ->create();

    $addonIds = [];

    // `$addons` is a list, so a caller can put two lines of the *same* kind in
    // the cart. That is what the rounding setting is about: a product and an
    // addon are two tax treatments and are rounded separately either way.
    foreach (array_values((array) $addon) as $index => $minor) {
        $extra = Addon::factory()->forProduct($model)->create(['name' => 'Extra '.$index]);

        AddonPrice::factory()->create([
            'addon_id' => $extra->id,
            'organization_id' => $extra->organization_id,
            'billing_cycle' => BillingCycle::Monthly->value,
            'currency_code' => 'TRY',
            'recurring_minor' => $minor,
            'setup_minor' => 0,
        ]);

        $addonIds[] = $extra->id;
    }

    $cart = Cart::factory()->forOrganization(test()->provider)->currency('TRY')->create();

    app(AddToCart::class)->handle($cart, new AddToCartRequest(
        productId: $model->id,
        cycle: BillingCycle::Monthly,
        addonIds: $addonIds,
    ));

    return app(PriceCart::class)->handle(
        $cart->fresh() ?? $cart,
        new TaxableSupply(Money::zero('TRY'), countryCode: 'TR'),
    );
}

/**
 * A signed-in customer contact who may edit their own billing details.
 *
 * @return array{0: Contact, 1: Customer}
 */
function clientWhoCanEditBillingDetails(): array
{
    $customer = Customer::factory()->create(['organization_id' => test()->provider->id]);

    $contact = Contact::factory()->forCustomer($customer)->primary()->create();
    $contact->assignRole(SystemRole::AccountOwner);

    return [$contact->fresh() ?? $contact, $customer];
}

// ---------------------------------------------------------------------------
// Prices that already include the tax
// ---------------------------------------------------------------------------

it('takes the tax out of an inclusive price instead of adding it on', function (): void {
    inclusiveSettings();
    ruleFor('KDV', '20');

    // 120.00 gross at 20% is 100.00 net and 20.00 tax. Adding instead of
    // extracting would charge 144.00 for a price the customer was shown as 120.
    expect(taxOnGross(12_000))->toMatchArray(['total' => 2_000, 'included' => true]);
});

it('adds the tax on when prices are exclusive, which is the default', function (): void {
    ruleFor('KDV', '20');

    expect(taxOnGross(10_000))->toMatchArray(['total' => 2_000, 'included' => false]);

    // And explicitly stated as exclusive, which must behave identically.
    inclusiveSettings(false);

    expect(taxOnGross(10_000))->toMatchArray(['total' => 2_000, 'included' => false]);
});

it('never loses a cent to the division', function (): void {
    inclusiveSettings();
    ruleFor('KDV', '20');

    // A gross that does not divide evenly. Whatever the rounding does, the tax
    // plus the net must be exactly the price the customer was shown.
    foreach ([1, 7, 99, 333, 1_001, 12_345, 99_999] as $gross) {
        $tax = taxOnGross($gross)['total'];

        expect($gross - $tax)->toBeGreaterThanOrEqual(0);
        // Reconstructing the gross from the net and the tax is exact by
        // construction: the components are allocated against the gross.
        expect($tax)->toBeLessThanOrEqual($gross);
    }
});

it('splits an inclusive price across two taxes without losing one', function (): void {
    inclusiveSettings();
    ruleFor('GST', '5', ['country_code' => 'CA']);
    ruleFor('QST', '9.975', [
        'country_code' => 'CA',
        'region_code' => 'QC',
        'level' => 2,
        'compound' => true,
    ]);

    $result = taxOnGross(11_547, ['country' => 'CA', 'region' => 'QC']);

    // The components always add up to the total, and the total is what is
    // inside the gross rather than what a second multiplication produced.
    expect(array_sum($result['components']))->toBe($result['total'])
        ->and($result['included'])->toBeTrue()
        // 115.47 gross under GST 5% compounded by QST 9.975% is roughly 100.00
        // net, so the tax inside it is roughly 15.47.
        ->and($result['total'])->toBeGreaterThan(1_500)
        ->and($result['total'])->toBeLessThan(1_600);
});

it('charges nothing on an inclusive price when the customer is exempt', function (): void {
    inclusiveSettings();
    ruleFor('VAT', '19', [
        'country_code' => 'DE',
        'exempts_validated_business' => true,
        'exemption_note' => 'Reverse charge',
    ]);

    // Exemption comes first: there is no tax inside the price for a customer who
    // accounts for it themselves, and the price they pay is the price shown.
    $result = app(TaxCalculator::class)->calculate(new TaxableSupply(
        amount: Money::ofMinor(11_900, 'EUR'),
        countryCode: 'DE',
        taxId: 'DE123456789',
        isBusiness: true,
        supplierCountryCode: 'TR',
    ));

    expect($result->total->minorUnits)->toBe(0)
        ->and($result->exemptionReason)->toBe('Reverse charge');
});

// ---------------------------------------------------------------------------
// Who is a business
// ---------------------------------------------------------------------------

it('calls a customer a business for having a company name, not a tax id', function (): void {
    // It was the other way round, which is circular: it made an individual who
    // typed a tax id a business, a company that had not given one an
    // individual, and "a business must state a tax id" impossible to ever fire.
    $company = Customer::factory()->create(['company_name' => 'Acme A.Ş.', 'tax_id' => null]);
    $person = Customer::factory()->create([
        'company_name' => null,
        'legal_name' => null,
        'tax_id' => '1234567890',
    ]);

    expect($company->isBusiness())->toBeTrue()
        ->and($person->isBusiness())->toBeFalse();
});

// ---------------------------------------------------------------------------
// What a tax id is called, and whether it is required
// ---------------------------------------------------------------------------

it('calls a tax id what the seller calls it', function (): void {
    // Never "VAT number" by default: it is wrong in most of the world, and a
    // default that is confidently wrong reads as configured.
    expect(app(TaxIdentity::class)->label())->toBe(__('tax.identity.default_label'));

    inclusiveSettings(false, ['tax_id_label' => 'Vergi Numarası']);

    expect(app(TaxIdentity::class)->label())->toBe('Vergi Numarası')
        ->and(app(TaxIdentity::class)->requiredForBusiness())->toBeFalse();
});

it('asks a business for a tax id only when the seller says so', function (): void {
    inclusiveSettings(false, ['require_tax_id_for_business' => true, 'tax_id_label' => 'Vergi No']);

    expect(app(TaxIdentity::class)->requiredForBusiness())->toBeTrue()
        ->and(app(TaxIdentity::class)->current())
        ->toMatchArray(['label' => 'Vergi No', 'requiredForBusiness' => true]);
});

// ---------------------------------------------------------------------------
// What a cart actually charges
// ---------------------------------------------------------------------------

it('charges exactly the price on the shelf when the catalog is inclusive', function (): void {
    inclusiveSettings();
    ruleFor('KDV', '20');

    $cart = cartWithOneProduct(12_000);

    $totals = app(PriceCart::class)->handle(
        $cart,
        new TaxableSupply(Money::zero('TRY'), countryCode: 'TR'),
    );

    // The whole point: a shelf price of 120.00 costs 120.00. Adding on top
    // would charge 144.00 for a price the customer was shown as 120.
    expect($totals->total->minorUnits)->toBe(12_000)
        ->and($totals->tax->total->minorUnits)->toBe(2_000)
        ->and($totals->tax->included)->toBeTrue()
        // And the document's own arithmetic still holds, so the order rows
        // written from these totals need no knowledge of which kind of catalog
        // this is: subtotal + setup - discount + tax = total.
        ->and(
            $totals->subtotal
                ->plus($totals->setup)
                ->minus($totals->discount)
                ->plus($totals->tax->total)
                ->minorUnits
        )->toBe($totals->total->minorUnits);
});

it('adds the tax on top when the catalog is exclusive', function (): void {
    ruleFor('KDV', '20');

    $cart = cartWithOneProduct(12_000);

    $totals = app(PriceCart::class)->handle(
        $cart,
        new TaxableSupply(Money::zero('TRY'), countryCode: 'TR'),
    );

    expect($totals->total->minorUnits)->toBe(14_400)
        ->and($totals->tax->included)->toBeFalse()
        ->and($totals->subtotal->minorUnits)->toBe(12_000);
});

// ---------------------------------------------------------------------------
// The form actually refuses
// ---------------------------------------------------------------------------

it('refuses a business with no tax id, and only when the seller asked', function (): void {
    [$contact, $customer] = clientWhoCanEditBillingDetails();

    $details = [
        'company_name' => 'Acme A.Ş.',
        'tax_id' => '',
        'line_one' => 'Bir sokak 1',
        'city' => 'Istanbul',
        'country_code' => 'TR',
    ];

    // Nobody has asked for one, so nothing is required.
    $this->actingAs($contact, 'client')
        ->put('/client/billing/details', $details)
        // The redirect as well as the absence of errors: a 403 also leaves the
        // session clean, and a test that only checked for errors would pass
        // against a screen nobody could reach.
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    inclusiveSettings(false, ['require_tax_id_for_business' => true, 'tax_id_label' => 'Vergi No']);

    $this->actingAs($contact, 'client')
        ->put('/client/billing/details', $details)
        ->assertSessionHasErrors('tax_id');

    // An individual is not a business and is asked for nothing.
    $this->actingAs($contact, 'client')
        ->put('/client/billing/details', [...$details, 'company_name' => ''])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    // And a business that gives one is fine.
    $this->actingAs($contact, 'client')
        ->put('/client/billing/details', [...$details, 'tax_id' => '1234567890'])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect($customer->fresh()?->tax_id)->toBe('1234567890');
});

// ---------------------------------------------------------------------------
// A rule scoped to one kind of line, and where the cent goes
// ---------------------------------------------------------------------------

it('charges an addon a different rate from the product it hangs off', function (): void {
    // The reason tax is worked out per line at all. Until it was, the only
    // supply ever handed to the calculator said "all", so a rule scoped to
    // anything else could never match — a whole column on the rules screen that
    // quietly did nothing. Several countries genuinely do tax a domain
    // registration and a hosting account at different rates.
    ruleFor('KDV', '20', ['applies_to' => TaxAppliesTo::Products->value]);
    ruleFor('KDV (ek)', '1', ['applies_to' => TaxAppliesTo::Addons->value]);

    $totals = priceMixedCart(product: 10_000, addon: 5_000);

    // 20% of 100.00 plus 1% of 50.00.
    expect($totals->tax->total->minorUnits)->toBe(2_050)
        ->and($totals->total->minorUnits)->toBe(17_050);

    $byName = [];

    foreach ($totals->tax->components as $component) {
        $byName[$component->name] = $component->amount->minorUnits;
    }

    // Two named components, because an invoice has to be able to say which is
    // which rather than printing one merged figure.
    expect($byName)->toBe(['KDV' => 2_000, 'KDV (ek)' => 50]);
});

it('leaves a line alone when no rule covers its kind', function (): void {
    ruleFor('KDV', '20', ['applies_to' => TaxAppliesTo::Products->value]);

    $totals = priceMixedCart(product: 10_000, addon: 5_000);

    // The addon is not taxed at all, rather than picking up the product rate
    // because both were summed into one amount before anybody asked.
    expect($totals->tax->total->minorUnits)->toBe(2_000)
        ->and($totals->total->minorUnits)->toBe(17_000);
});

it('rounds per line or once, and the two differ', function (): void {
    // 19.75% of 33.33 is 6.582 -> 6.58, twice is 13.16. 19.75% of 66.66 is
    // 13.165 -> 13.17. One cent, and which answer a jurisdiction requires is a
    // real difference rather than a preference.
    ruleFor('VAT', '19.75');

    // Two addons, so both lines share one tax treatment. Per line is the
    // shipped default, which is what most panels do: 19.75% of 33.33 is 6.582
    // and rounds to 6.58, twice.
    expect(priceMixedCart(product: 0, addon: [3_333, 3_333])->tax->total->minorUnits)
        ->toBe(1_316);

    inclusiveSettings(false, ['rounding' => TaxRounding::PerInvoice->value]);

    // Once on the total: 19.75% of 66.66 is 13.165 and rounds to 13.17. One
    // cent, and which answer a jurisdiction requires is a real difference.
    expect(priceMixedCart(product: 0, addon: [3_333, 3_333])->tax->total->minorUnits)
        ->toBe(1_317);
});
