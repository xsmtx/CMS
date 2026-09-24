<?php

declare(strict_types=1);

use App\Domain\Organizations\OrganizationType;
use App\Domain\Shared\Money;
use App\Domain\Tax\Contracts\TaxCalculator;
use App\Domain\Tax\TaxableSupply;
use App\Domain\Tax\TaxAppliesTo;
use App\Domain\Tax\TaxCustomerKind;
use App\Infrastructure\Organizations\Models\Organization;
use App\Infrastructure\Tax\Models\TaxRule;
use App\Support\Organizations\OrganizationContext;
use Carbon\CarbonImmutable;
use Database\Seeders\ProviderOrganizationSeeder;

/**
 * Tax as rows, applied.
 *
 * Every case here is a country somebody actually sells in, because the point of
 * ADR 0045 is that core knows none of them and an operator can still express
 * them: one national rate, a rate that differs by region, a compound provincial
 * tax, a reverse charge, a rate that changed on a date, and a domain taxed
 * differently from hosting.
 *
 * If a future change makes any of these need code rather than rows, the design
 * has failed.
 */
beforeEach(function (): void {
    $this->seed(ProviderOrganizationSeeder::class);

    $this->provider = Organization::query()
        ->where('type', OrganizationType::Provider->value)
        ->firstOrFail();

    app(OrganizationContext::class)->set($this->provider->id);
});

function taxOn(int $minor, array $supply = []): array
{
    $result = app(TaxCalculator::class)->calculate(new TaxableSupply(
        amount: Money::ofMinor($minor, 'EUR'),
        countryCode: $supply['country'] ?? null,
        stateCode: $supply['region'] ?? null,
        postalCode: $supply['postcode'] ?? null,
        taxId: $supply['taxId'] ?? null,
        isBusiness: $supply['isBusiness'] ?? false,
        supplierCountryCode: $supply['sellerCountry'] ?? null,
        appliesTo: $supply['appliesTo'] ?? TaxAppliesTo::All,
    ));

    return [
        'minor' => $result->total->minorUnits,
        'components' => array_map(
            static fn (object $component): array => [
                'name' => $component->name,
                'rate' => $component->rate,
                'minor' => $component->amount->minorUnits,
            ],
            $result->components,
        ),
        'exemption' => $result->exemptionReason,
    ];
}

it('charges nothing at all until somebody writes a rule', function (): void {
    // The default driver reads rows, and an installation that has never opened
    // the screen has none. It sells, and it charges no tax.
    expect(taxOn(10_000)['minor'])->toBe(0);
});

it('charges one national rate', function (): void {
    TaxRule::factory()->forOrganization($this->provider->id)->rate('KDV', 200_000)->in('TR')->create();

    $result = taxOn(10_000, ['country' => 'TR']);

    expect($result['minor'])->toBe(2_000)
        ->and($result['components'][0]['name'])->toBe('KDV')
        ->and($result['components'][0]['rate'])->toBe('20.00');

    // And nothing outside the country the rule names.
    expect(taxOn(10_000, ['country' => 'DE'])['minor'])->toBe(0);
});

it('charges a different rate in each country', function (): void {
    TaxRule::factory()->forOrganization($this->provider->id)->rate('KDV', 200_000)->in('TR')->create();
    TaxRule::factory()->forOrganization($this->provider->id)->rate('MwSt', 190_000)->in('DE')->create();
    TaxRule::factory()->forOrganization($this->provider->id)->rate('VAT', 200_000)->in('GB')->create();

    // The requirement this whole table exists for: one installation, three
    // countries, no code.
    expect(taxOn(10_000, ['country' => 'TR'])['minor'])->toBe(2_000)
        ->and(taxOn(10_000, ['country' => 'DE'])['minor'])->toBe(1_900)
        ->and(taxOn(10_000, ['country' => 'GB'])['minor'])->toBe(2_000);
});

it('lets a region beat its own country', function (): void {
    TaxRule::factory()->forOrganization($this->provider->id)->rate('Sales tax', 40_000)->in('US')->create();
    TaxRule::factory()->forOrganization($this->provider->id)->rate('Sales tax', 72_500)->in('US', 'CA')->create();

    // Most specific wins, which is the answer an operator writing a national
    // rate plus one state expects.
    expect(taxOn(10_000, ['country' => 'US', 'region' => 'NY'])['minor'])->toBe(400)
        ->and(taxOn(10_000, ['country' => 'US', 'region' => 'CA'])['minor'])->toBe(725);
});

it('charges a second tax, and compounds it only when the rule says so', function (): void {
    TaxRule::factory()->forOrganization($this->provider->id)
        ->rate('GST', 50_000)->in('CA')->atLevel(1)->create();

    TaxRule::factory()->forOrganization($this->provider->id)
        ->rate('QST', 99_750)->in('CA', 'QC')->atLevel(2, compound: true)->create();

    TaxRule::factory()->forOrganization($this->provider->id)
        ->rate('PST', 70_000)->in('CA', 'BC')->atLevel(2)->create();

    // Quebec charges its provincial tax on the GST-inclusive amount.
    $quebec = taxOn(10_000, ['country' => 'CA', 'region' => 'QC']);

    expect($quebec['components'][0]['minor'])->toBe(500)
        ->and($quebec['components'][1]['minor'])->toBe(1_047)
        ->and($quebec['minor'])->toBe(1_547);

    // British Columbia charges its own on the base alone.
    $bc = taxOn(10_000, ['country' => 'CA', 'region' => 'BC']);

    expect($bc['components'][1]['minor'])->toBe(700)
        ->and($bc['minor'])->toBe(1_200);
});

it('charges nothing to a business abroad that gave a tax id, and says why', function (): void {
    TaxRule::factory()->forOrganization($this->provider->id)
        ->rate('VAT', 190_000)
        ->in('DE')
        ->exemptingBusinesses('Reverse charge, article 196')
        ->create();

    $exempt = taxOn(10_000, [
        'country' => 'DE',
        'isBusiness' => true,
        'taxId' => 'DE123456789',
        'sellerCountry' => 'TR',
    ]);

    expect($exempt['minor'])->toBe(0)
        ->and($exempt['exemption'])->toBe('Reverse charge, article 196');

    // A business in the seller's own country still pays.
    $athome = taxOn(10_000, [
        'country' => 'DE',
        'isBusiness' => true,
        'taxId' => 'DE123456789',
        'sellerCountry' => 'DE',
    ]);

    expect($athome['minor'])->toBe(1_900);

    // And a business that gave no id pays, because there is nothing to exempt
    // against.
    expect(taxOn(10_000, ['country' => 'DE', 'isBusiness' => true])['minor'])->toBe(1_900);
});

it('applies a rule only to what it says it applies to', function (): void {
    TaxRule::factory()->forOrganization($this->provider->id)
        ->rate('VAT', 200_000)->in('GB')->on(TaxAppliesTo::Products)->create();

    expect(taxOn(10_000, ['country' => 'GB', 'appliesTo' => TaxAppliesTo::Products])['minor'])->toBe(2_000)
        // A domain registration is not a product, and this rule does not reach
        // it. Several countries treat the two differently.
        ->and(taxOn(10_000, ['country' => 'GB', 'appliesTo' => TaxAppliesTo::Domains])['minor'])->toBe(0);
});

it('applies a rule only to the kind of customer it names', function (): void {
    TaxRule::factory()->forOrganization($this->provider->id)
        ->rate('VAT', 200_000)->in('GB')->forKind(TaxCustomerKind::Individual)->create();

    expect(taxOn(10_000, ['country' => 'GB'])['minor'])->toBe(2_000)
        ->and(taxOn(10_000, ['country' => 'GB', 'isBusiness' => true])['minor'])->toBe(0);
});

it('honours the date a rate changed', function (): void {
    TaxRule::factory()->forOrganization($this->provider->id)
        ->rate('VAT', 170_000)->in('GB')
        ->create(['ends_on' => '2026-03-31']);

    TaxRule::factory()->forOrganization($this->provider->id)
        ->rate('VAT', 200_000)->in('GB')
        ->create(['starts_on' => '2026-04-01']);

    CarbonImmutable::setTestNow('2026-03-15');
    expect(taxOn(10_000, ['country' => 'GB'])['minor'])->toBe(1_700);

    CarbonImmutable::setTestNow('2026-06-15');
    expect(taxOn(10_000, ['country' => 'GB'])['minor'])->toBe(2_000);

    CarbonImmutable::setTestNow();
});

it('ignores a rule somebody switched off', function (): void {
    TaxRule::factory()->forOrganization($this->provider->id)
        ->rate('VAT', 200_000)->in('GB')->inactive()->create();

    expect(taxOn(10_000, ['country' => 'GB'])['minor'])->toBe(0);
});

it('matches a postcode pattern, and is not a regular expression', function (): void {
    TaxRule::factory()->forOrganization($this->provider->id)
        ->rate('City tax', 10_000)->in('US', 'NY', '100*')->create();

    expect(taxOn(10_000, ['country' => 'US', 'region' => 'NY', 'postcode' => '10001'])['minor'])->toBe(100)
        ->and(taxOn(10_000, ['country' => 'US', 'region' => 'NY', 'postcode' => '20001'])['minor'])->toBe(0);
});

it('charges one rule per level even when two could match', function (): void {
    $lower = TaxRule::factory()->forOrganization($this->provider->id)
        ->rate('VAT', 100_000)->in('GB')->create(['priority' => 0]);

    TaxRule::factory()->forOrganization($this->provider->id)
        ->rate('VAT', 200_000)->in('GB')->create(['priority' => 5]);

    // Two national rates is a mistake in the configuration; charging both would
    // turn it into a mistake on an invoice. Priority decides, and the answer is
    // stable rather than whatever the database returned first.
    expect(taxOn(10_000, ['country' => 'GB'])['minor'])->toBe(2_000)
        ->and($lower->fresh())->not->toBeNull();
});

it('rounds half up, once, on integer minor units', function (): void {
    TaxRule::factory()->forOrganization($this->provider->id)->rate('VAT', 197_500)->in('GB')->create();

    // 333 * 19.75% = 65.7675 → 66. The arithmetic happens in `Money`, which is
    // the one place this platform multiplies money.
    expect(taxOn(333, ['country' => 'GB'])['minor'])->toBe(66);
});

it('taxes nothing on a zero or negative amount', function (): void {
    TaxRule::factory()->forOrganization($this->provider->id)->rate('VAT', 200_000)->in('GB')->create();

    expect(taxOn(0, ['country' => 'GB'])['minor'])->toBe(0)
        ->and(taxOn(-5_000, ['country' => 'GB'])['minor'])->toBe(0);
});

it('uses the seller rules rather than the acting organization', function (): void {
    TaxRule::factory()->forOrganization($this->provider->id)->rate('KDV', 200_000)->in('TR')->create();

    $customer = Organization::factory()->create([
        'type' => OrganizationType::Customer->value,
        'parent_id' => $this->provider->id,
    ]);

    // At checkout the boundary is the customer's own. The rules belong to
    // whoever sells to them, which `ResolveSeller` answers — and asking inside
    // the customer's subtree would find none and charge nothing, which is the
    // worst failure available here.
    app(OrganizationContext::class)->set($customer->id);

    expect(taxOn(10_000, ['country' => 'TR'])['minor'])->toBe(2_000);
});
