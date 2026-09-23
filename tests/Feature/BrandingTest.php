<?php

declare(strict_types=1);

use App\Application\Access\SyncPermissions;
use App\Application\Branding\ResolveBrand;
use App\Domain\Access\PermissionRegistry;
use App\Domain\Access\SystemRole;
use App\Domain\Branding\Brand;
use App\Domain\Licensing\Contracts\Entitlements;
use App\Domain\Licensing\Feature;
use App\Infrastructure\Branding\Models\BrandSetting;
use App\Infrastructure\Crm\Models\Customer;
use App\Infrastructure\Identity\Models\StaffUser;
use App\Infrastructure\Organizations\Models\Organization;
use App\Support\Branding\CurrentBrand;
use Database\Seeders\ProviderOrganizationSeeder;
use Database\Seeders\SystemRoleSeeder;
use Illuminate\Support\Facades\Schema;
use Inertia\Testing\AssertableInertia;

beforeEach(function (): void {
    $this->withoutVite();

    $this->seed(ProviderOrganizationSeeder::class);
    app(SyncPermissions::class)->handle(app(PermissionRegistry::class));
    $this->seed(SystemRoleSeeder::class);

    $this->provider = Organization::query()
        ->withoutGlobalScope('organization')
        ->whereNull('parent_id')
        ->sole();

    $this->admin = StaffUser::factory()->create();
    $this->admin->assignRole(SystemRole::Administrator);
    $this->admin = $this->admin->fresh();
});

it('falls back to the application name when nobody has branded anything', function (): void {
    $brand = app(ResolveBrand::class)->forOrganization($this->provider->id);

    // A fresh installation shows something rather than an empty header.
    expect($brand->name)->toBe($this->provider->name);
});

it('shows the brand an organization set', function (): void {
    BrandSetting::factory()->forOrganization($this->provider)->create([
        'trading_name' => 'Northwind Hosting',
        'legal_name' => 'Northwind Hosting Ltd',
    ]);

    $brand = app(ResolveBrand::class)->forOrganization($this->provider->id);

    expect($brand->name)->toBe('Northwind Hosting')
        // An invoice needs the legal name; a page header needs the trading
        // one. Both are available without either being required.
        ->and($brand->documentName())->toBe('Northwind Hosting Ltd');
});

it('fills a reseller’s holes from its parent, field by field', function (): void {
    BrandSetting::factory()->forOrganization($this->provider)->create([
        'trading_name' => 'Provider',
        'accent_color' => '#111111',
        'invoice_footer' => 'Registered in Ireland.',
    ]);

    $reseller = Organization::factory()->reseller($this->provider)->create(['name' => 'Reseller A']);

    BrandSetting::factory()->forOrganization($reseller)->create([
        'trading_name' => 'Aurora Hosting',
        'accent_color' => '#2563eb',
        // Nothing else set.
    ]);

    $brand = app(ResolveBrand::class)->forOrganization($reseller->id);

    // The reseller's own where it has one, the provider's where it does
    // not — per field, not per row.
    expect($brand->name)->toBe('Aurora Hosting')
        ->and($brand->accentColor)->toBe('#2563eb')
        ->and($brand->invoiceFooter)->toBe('Registered in Ireland.');
});

it('treats a cleared field as inherited rather than as empty', function (): void {
    BrandSetting::factory()->forOrganization($this->provider)->create([
        'trading_name' => 'Provider',
        'support_email' => 'help@provider.test',
    ]);

    $reseller = Organization::factory()->reseller($this->provider)->create();

    BrandSetting::factory()->forOrganization($reseller)->create([
        'trading_name' => 'Aurora',
        // Somebody typed an address and then deleted it. That has to mean
        // "use my parent's", or they can never undo the change.
        'support_email' => '',
    ]);

    expect(app(ResolveBrand::class)->forOrganization($reseller->id)->supportEmail)
        ->toBe('help@provider.test');
});

it('does not inherit the vendor-mark switch', function (): void {
    BrandSetting::factory()->forOrganization($this->provider)->create([
        'hide_vendor_mark' => true,
    ]);

    $reseller = Organization::factory()->reseller($this->provider)->create();
    BrandSetting::factory()->forOrganization($reseller)->create(['hide_vendor_mark' => false]);

    // Whether a reseller may remove the mark is that reseller's
    // entitlement, not their parent's choice.
    expect(app(ResolveBrand::class)->forOrganization($reseller->id)->hideVendorMark)->toBeFalse();
});

it('shows the storefront the installation’s brand', function (): void {
    BrandSetting::factory()->forOrganization($this->provider)->create([
        'trading_name' => 'Northwind Hosting',
    ]);

    $this->get('/')
        ->assertOk()
        ->assertSee('Northwind Hosting', false);
});

it('shows a customer the brand of whoever sells to them', function (): void {
    BrandSetting::factory()->forOrganization($this->provider)->create(['trading_name' => 'Provider']);

    $reseller = Organization::factory()->reseller($this->provider)->create();
    BrandSetting::factory()->forOrganization($reseller)->create(['trading_name' => 'Aurora Hosting']);

    // The customer's own organization sits under the reseller, which is
    // what makes the reseller the seller.
    $customerOrganization = Organization::factory()->customerOf($reseller)->create();
    $customer = Customer::factory()->forOrganization($customerOrganization)->create();

    // A customer is an organization of its own, so asking for "the
    // customer's brand" would show them their own name. They see whoever
    // sells to them — never the provider behind that reseller.
    expect(app(CurrentBrand::class)->forDocument($customer->organization_id)->name)
        ->toBe('Aurora Hosting');
});

it('puts the brand’s colours on the storefront as custom properties', function (): void {
    BrandSetting::factory()->forOrganization($this->provider)->create([
        'trading_name' => 'Northwind',
        'accent_color' => '#2563eb',
    ]);

    // Overriding the token everything is already built on, rather than
    // regenerating a stylesheet per brand.
    $this->get('/')
        ->assertOk()
        ->assertSee('--color-accent: #2563eb', false)
        // And the states built on it. A brand that set its own accent and
        // then hovered to the platform's blue is half a rebrand, and it is
        // the kind of bug nobody reports and everybody notices.
        ->assertSee('--color-accent-hover: color-mix(in oklab, #2563eb', false)
        ->assertSee('--color-accent-subtle: color-mix(in oklab, #2563eb', false);
});

it('shows the platform mark until a licence says otherwise', function (): void {
    $this->get('/')->assertOk()->assertSee('Powered by InfraCMS', false);
});

it('removes the platform mark when the operator asks and the licence allows', function (): void {
    BrandSetting::factory()->forOrganization($this->provider)->create([
        'trading_name' => 'Northwind',
        'hide_vendor_mark' => true,
    ]);

    $this->get('/')->assertOk()->assertDontSee('Powered by InfraCMS', false);
});

it('keeps the mark when the licence does not allow removing it', function (): void {
    BrandSetting::factory()->forOrganization($this->provider)->create([
        'trading_name' => 'Northwind',
        'hide_vendor_mark' => true,
    ]);

    $this->app->bind(Entitlements::class, fn (): Entitlements => new class implements Entitlements
    {
        public function allows(string $feature): bool
        {
            return $feature !== Feature::RemoveVendorMark->value;
        }

        public function limit(string $name): ?int
        {
            return null;
        }
    });

    // The stored choice survives, so restoring the licence restores it —
    // but it does not take effect while the entitlement is gone, and that
    // has to be true of the rendered page rather than only of the save.
    $this->get('/')->assertOk()->assertSee('Powered by InfraCMS', false);

    expect(BrandSetting::query()->sole()->hide_vendor_mark)->toBeTrue();
});

it('allows everything by default, because a self-hosted installation has no licence server', function (): void {
    // A gate whose default is deny turns an unreachable licence API into an
    // outage nobody can distinguish from the product being broken.
    expect(app(Entitlements::class)->allows(Feature::RemoveVendorMark->value))->toBeTrue();
});

it('shows staff the settings screen the navigation has always pointed at', function (): void {
    $this->actingAs($this->admin, 'staff')
        ->get('/admin/settings')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->component('Admin/Settings/Index')
            ->has('surfaces', 3)
            ->where('can.manage', true));
});

it('saves branding and audits who changed it', function (): void {
    $this->actingAs($this->admin, 'staff')
        ->put('/admin/settings/brand', [
            'trading_name' => 'Northwind Hosting',
            'legal_name' => 'Northwind Hosting Ltd',
        ])
        ->assertRedirect();

    expect(BrandSetting::query()->sole()->trading_name)->toBe('Northwind Hosting')
        // "Who changed the company's legal name on its invoices" gets asked
        // once, in circumstances nobody enjoys.
        ->and(DB::table('audit_logs')->where('action', 'branding.updated')->count())->toBe(1);
});

it('refuses a colour that is not a colour', function (): void {
    $this->actingAs($this->admin, 'staff')
        ->put('/admin/settings/brand', ['accent_color' => 'red; } body { display: none'])
        ->assertSessionHasErrors('accent_color');
});

it('refuses a logo served over plain http', function (): void {
    // Blocked by the browser on a checkout page anyway, and a mixed-content
    // warning on the one page a customer is deciding whether to trust.
    $this->actingAs($this->admin, 'staff')
        ->put('/admin/settings/brand', ['logo_url' => 'http://cdn.example.test/logo.svg'])
        ->assertSessionHasErrors('logo_url');
});

it('refuses settings to staff without the permission', function (): void {
    $this->actingAs(StaffUser::factory()->create(), 'staff')
        ->get('/admin/settings')
        ->assertForbidden();
});

/**
 * A brand is a public thing: a name, an address, colours, URLs. It appears
 * on a storefront, in an email footer and on a document a customer keeps.
 *
 * So nothing on it may be a credential. The email *identity* lives here —
 * the from-name and the from-address, which every recipient sees anyway —
 * while whatever sends that mail stays in configuration, with the rest of
 * this platform's secrets. This test is the shape of that promise: a
 * column added later called `smtp_password` fails here rather than in a
 * support ticket.
 */
it('holds nothing that could be a secret', function (): void {
    $columns = Schema::getColumnListing('brand_settings');

    $suspicious = array_values(array_filter(
        $columns,
        static fn (string $column): bool => (bool) preg_match(
            '/(secret|password|token|api_?key|credential|private)/i',
            $column,
        ),
    ));

    expect($suspicious)->toBe([]);

    // And the value object that reaches a template carries no more than
    // the row does: a brand handed to a Blade view is a brand a theme
    // author can print in full.
    $public = array_map(
        static fn (ReflectionProperty $property): string => $property->getName(),
        new ReflectionClass(Brand::class)->getProperties(ReflectionProperty::IS_PUBLIC),
    );

    $leaks = array_values(array_filter(
        $public,
        static fn (string $name): bool => (bool) preg_match(
            '/(secret|password|token|apiKey|credential|private)/i',
            $name,
        ),
    ));

    expect($leaks)->toBe([]);
});
