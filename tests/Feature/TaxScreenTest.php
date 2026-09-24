<?php

declare(strict_types=1);

use App\Application\Access\SyncPermissions;
use App\Domain\Access\PermissionRegistry;
use App\Domain\Access\SystemRole;
use App\Domain\Tax\TaxRounding;
use App\Http\Middleware\HandleInertiaRequests;
use App\Infrastructure\Billing\Models\Invoice;
use App\Infrastructure\Identity\Models\StaffUser;
use App\Infrastructure\Tax\Models\TaxRule;
use App\Infrastructure\Tax\Models\TaxSetting;
use Database\Seeders\ProviderOrganizationSeeder;
use Database\Seeders\SystemRoleSeeder;
use Illuminate\Http\Request;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;

/**
 * The Tax screen, with every button pressed.
 *
 * `ConfigurableTaxTest` proves the arithmetic against six countries; this file
 * proves an operator can reach it. The two are separate on purpose: a
 * calculator nobody can configure is the same outage as a calculator that is
 * wrong, and this product has already shipped screens whose actions posted to
 * routes that had moved.
 *
 * The owner-only case is first, because it is the requirement rather than a
 * detail: an Administrator holds every staff permission by design, so if tax
 * were behind a permission, every reseller's Administrator could set their own
 * VAT rate.
 */
beforeEach(function (): void {
    $this->withoutVite();

    $this->seed(ProviderOrganizationSeeder::class);
    app(SyncPermissions::class)->handle(app(PermissionRegistry::class));
    $this->seed(SystemRoleSeeder::class);

    $this->owner = StaffUser::factory()->create();
    $this->owner->assignRole(SystemRole::SuperAdmin);
    $this->owner = $this->owner->fresh();

    $this->administrator = StaffUser::factory()->create();
    $this->administrator->assignRole(SystemRole::Administrator);
    $this->administrator = $this->administrator->fresh();
});

/**
 * One rule, as the form posts it: a percentage typed by a human.
 *
 * @return array<string, mixed>
 */
function taxRuleForm(array $overrides = []): array
{
    return array_merge([
        'name' => 'KDV',
        'rate' => '20',
        'country_code' => 'TR',
        'region_code' => '',
        'postcode_pattern' => '',
        'level' => 1,
        'compound' => false,
        'applies_to' => 'all',
        'customer_kind' => 'all',
        'exempts_validated_business' => false,
        'exemption_note' => '',
        'priority' => 0,
        'starts_on' => '',
        'ends_on' => '',
        'is_active' => true,
        'notes' => '',
    ], $overrides);
}

/**
 * An invoice that has already gone to a customer, with tax on it.
 */
function issuedInvoiceForTax(): Invoice
{
    return Invoice::factory()->create([
        'subtotal_minor' => 10_000,
        'tax_minor' => 2_000,
        'total_minor' => 12_000,
    ]);
}

/**
 * What the rules do to one amount, asked for by name on the route the list
 * already uses — so an ordinary page load computes nothing.
 */
function askTaxPreview(array $query): TestResponse
{
    $request = Request::create('/admin/tax');

    return test()->get('/admin/tax?'.http_build_query($query), [
        'X-Inertia' => 'true',
        'X-Inertia-Version' => (string) app(HandleInertiaRequests::class)->version($request),
        'X-Inertia-Partial-Component' => 'Admin/Tax/Index',
        'X-Inertia-Partial-Data' => 'preview',
    ]);
}

it('is the owner\'s screen and nobody else\'s', function (): void {
    $this->actingAs($this->administrator, 'staff')
        ->get('/admin/tax')
        ->assertForbidden();

    $this->actingAs($this->administrator, 'staff')
        ->post('/admin/tax/rules', taxRuleForm())
        ->assertForbidden();

    $this->actingAs($this->owner, 'staff')
        ->get('/admin/tax')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->component('Admin/Tax/Index')
            ->has('rules', 0)
            ->has('options.appliesTo')
            ->has('options.customerKinds')
            ->has('options.rounding')
            // Absent, not null: the preview is built only when asked for.
            ->missing('preview')
        );
});

it('adds, edits and removes a rule from the screen', function (): void {
    $this->actingAs($this->owner, 'staff')
        ->post('/admin/tax/rules', taxRuleForm())
        ->assertRedirect();

    $rule = TaxRule::query()->firstOrFail();

    expect($rule->name)->toBe('KDV')
        ->and($rule->rate_ppm)->toBe(200_000)
        ->and($rule->country_code)->toBe('TR');

    // A rate change is a new rate on the same row here; giving the old one an
    // end date instead is the operator's choice, and the screen says so.
    $this->actingAs($this->owner, 'staff')
        ->put('/admin/tax/rules/'.$rule->id, taxRuleForm(['rate' => '18', 'name' => 'KDV (eski)']))
        ->assertRedirect();

    expect($rule->fresh()?->rate_ppm)->toBe(180_000);

    $this->actingAs($this->owner, 'staff')
        ->delete('/admin/tax/rules/'.$rule->id)
        ->assertRedirect();

    expect(TaxRule::query()->count())->toBe(0);
});

it('keeps a rate the operator typed exactly, to four decimal places', function (): void {
    // Quebec is 9.975%. Basis points cannot express it and a float cast gives
    // 99749 — the reason the column is parts per million and the conversion
    // happens once, in the request.
    $this->actingAs($this->owner, 'staff')
        ->post('/admin/tax/rules', taxRuleForm([
            'name' => 'QST',
            'rate' => '9.975',
            'country_code' => 'CA',
            'region_code' => 'QC',
            'level' => 2,
            'compound' => true,
        ]))
        ->assertRedirect();

    $rule = TaxRule::query()->firstOrFail();

    expect($rule->rate_ppm)->toBe(99_750)
        ->and($rule->percentage())->toBe('9.975')
        ->and($rule->compound)->toBeTrue();
});

it('refuses a rate that is not a decimal an authority would publish', function (): void {
    foreach (['1e2', '0x14', 'twenty', '20.123456', '-5'] as $rate) {
        $this->actingAs($this->owner, 'staff')
            ->post('/admin/tax/rules', taxRuleForm(['rate' => $rate]))
            ->assertSessionHasErrors('rate');
    }

    expect(TaxRule::query()->count())->toBe(0);
});

it('saves how tax behaves', function (): void {
    $this->actingAs($this->owner, 'staff')
        ->put('/admin/tax/settings', [
            'prices_include_tax' => true,
            'rounding' => TaxRounding::PerInvoice->value,
            'tax_id_label' => 'Vergi No',
            'require_tax_id_for_business' => true,
            'exemption_note' => 'Reverse charge, article 196',
        ])
        ->assertRedirect();

    $settings = TaxSetting::query()->firstOrFail();

    expect($settings->prices_include_tax)->toBeTrue()
        ->and($settings->rounding)->toBe(TaxRounding::PerInvoice)
        ->and($settings->tax_id_label)->toBe('Vergi No')
        ->and($settings->require_tax_id_for_business)->toBeTrue();

    // Saving twice is one row, not two: the settings belong to the seller.
    $this->actingAs($this->owner, 'staff')
        ->put('/admin/tax/settings', [
            'rounding' => TaxRounding::PerLine->value,
            'tax_id_label' => '',
        ])
        ->assertRedirect();

    expect(TaxSetting::query()->count())->toBe(1)
        ->and(TaxSetting::query()->firstOrFail()->rounding)->toBe(TaxRounding::PerLine)
        // `??`, not `$data['key']`: a field the form left empty is absent.
        ->and(TaxSetting::query()->firstOrFail()->tax_id_label)->toBeNull();
});

it('shows what the rules do to an amount, through the real calculator', function (): void {
    $this->actingAs($this->owner, 'staff')
        ->post('/admin/tax/rules', taxRuleForm(['name' => 'GST', 'rate' => '5', 'country_code' => 'CA']))
        ->assertRedirect();

    $this->actingAs($this->owner, 'staff')
        ->post('/admin/tax/rules', taxRuleForm([
            'name' => 'QST',
            'rate' => '9.975',
            'country_code' => 'CA',
            'region_code' => 'QC',
            'level' => 2,
            'compound' => true,
        ]))
        ->assertRedirect();

    $this->actingAs($this->owner, 'staff');

    // 10000 + GST 500 = 10500; QST compounds on that: 9.975% of 10500 = 1047.
    askTaxPreview([
        'amount' => 10_000,
        'currency' => 'CAD',
        'country_code' => 'ca',
        'region_code' => 'qc',
    ])
        ->assertOk()
        // `assertInertia` cannot read a partial: it pulls the page object out
        // of a rendered view, and a partial reload renders none.
        ->assertJsonPath('props.preview.components.0.name', 'GST')
        ->assertJsonPath('props.preview.components.1.name', 'QST')
        ->assertJsonCount(2, 'props.preview.components');

    // A country with no rule charges nothing, and the panel says so rather
    // than leaving somebody to assume a rate they never entered applies.
    askTaxPreview(['amount' => 10_000, 'currency' => 'CAD', 'country_code' => 'NZ'])
        ->assertOk()
        ->assertJsonCount(0, 'props.preview.components');

    // Nothing to work out is null rather than a zero somebody would read as
    // an answer.
    askTaxPreview(['amount' => 0, 'currency' => 'CAD'])
        ->assertOk()
        ->assertJsonPath('props.preview', null);

    // An exemption is previewable without the id ever reaching the URL. The
    // panel is a GET, so everything it asks lands in the address bar, in browser
    // history and in the access log; the rules only ever check that a tax id
    // exists, so that is the only thing the form sends.
    $this->actingAs($this->owner, 'staff')
        ->post('/admin/tax/rules', taxRuleForm([
            'name' => 'VAT',
            'rate' => '19',
            // No country on the rule, so it is the seller's own rate charged
            // everywhere — which is the shape a reverse charge exempts from. A
            // rule naming the *customer's* country would be that country's own
            // tax, and a business there pays it.
            'country_code' => '',
            'exempts_validated_business' => true,
            'exemption_note' => 'Reverse charge',
        ]))
        ->assertRedirect();

    $this->actingAs($this->owner, 'staff');

    askTaxPreview([
        'amount' => 10_000,
        'currency' => 'EUR',
        'country_code' => 'DE',
        'is_business' => 1,
        'has_tax_id' => 1,
    ])
        ->assertOk()
        ->assertJsonPath('props.preview.exemption', 'Reverse charge');

    // Without it, the same customer is charged.
    askTaxPreview([
        'amount' => 10_000,
        'currency' => 'EUR',
        'country_code' => 'DE',
        'is_business' => 1,
        'has_tax_id' => 0,
    ])
        ->assertOk()
        ->assertJsonPath('props.preview.exemption', null);

});

it('does not touch an invoice that was already issued', function (): void {
    // The point of a frozen document (ADR 0023) meeting rows an operator edits:
    // changing a rate changes what the *next* invoice charges and nothing that
    // has already gone to a customer.
    $this->actingAs($this->owner, 'staff')
        ->post('/admin/tax/rules', taxRuleForm(['rate' => '20']))
        ->assertRedirect();

    $invoice = issuedInvoiceForTax();
    $before = [$invoice->tax_minor, $invoice->total_minor];

    $rule = TaxRule::query()->firstOrFail();

    $this->actingAs($this->owner, 'staff')
        ->put('/admin/tax/rules/'.$rule->id, taxRuleForm(['rate' => '1']))
        ->assertRedirect();

    $this->actingAs($this->owner, 'staff')
        ->delete('/admin/tax/rules/'.$rule->id)
        ->assertRedirect();

    $invoice->refresh();

    expect([$invoice->tax_minor, $invoice->total_minor])->toBe($before);
});
