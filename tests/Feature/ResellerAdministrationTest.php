<?php

declare(strict_types=1);

use App\Application\Access\SyncPermissions;
use App\Application\Organizations\CreateReseller;
use App\Application\Organizations\ResellerAttributes;
use App\Domain\Access\PermissionRegistry;
use App\Domain\Access\SystemRole;
use App\Domain\Catalog\BillingCycle;
use App\Domain\Organizations\OrganizationType;
use App\Domain\Resellers\ResellerLedgerKind;
use App\Infrastructure\Catalog\Models\Product;
use App\Infrastructure\Crm\Models\Customer;
use App\Infrastructure\Identity\Models\StaffUser;
use App\Infrastructure\Organizations\Models\Organization;
use App\Infrastructure\Resellers\Models\ResellerLedgerEntry;
use App\Infrastructure\Resellers\Models\ResellerPrice;
use App\Infrastructure\Resellers\Models\ResellerProduct;
use App\Support\Organizations\OrganizationContext;
use Database\Seeders\ProviderOrganizationSeeder;
use Database\Seeders\SystemRoleSeeder;
use Inertia\Testing\AssertableInertia;

/**
 * The screens that operate the reseller programme.
 *
 * The rule the whole file is about: **`resellers.administer` is a gate, not a
 * permission.** A reseller's own Administrator holds every staff permission
 * there is, by design — the seeder gives that role the whole staff scope so
 * that a permission added in a later phase reaches it. So a permission called
 * `resellers.manage` would land on a reseller's own administrator and let
 * them set their own margins and write their own balance.
 *
 * Everything else here is the ordinary shape: absence is a refusal, an exact
 * price beats a margin, and a row that is not a reseller answers 404 rather
 * than 403.
 */
beforeEach(function (): void {
    $this->withoutVite();

    $this->seed(ProviderOrganizationSeeder::class);
    app(SyncPermissions::class)->handle(app(PermissionRegistry::class));
    $this->seed(SystemRoleSeeder::class);

    $this->provider = StaffUser::factory()->create();
    $this->provider->assignRole(SystemRole::Administrator);
    $this->provider = $this->provider->fresh();

    $this->product = Product::factory()->create(['name' => 'Starter Hosting']);
});

/** A reseller, and the person who runs it. */
function makeReseller(string $name = 'Anatolia Hosting', string $email = 'owner@anatolia.test'): array
{
    return app(CreateReseller::class)->handle(new ResellerAttributes(
        name: $name,
        ownerName: $name.' Owner',
        ownerEmail: $email,
    ));
}

it('lists resellers with the three numbers somebody opens the screen for', function (): void {
    $reseller = makeReseller()['organization'];

    $this->actingAs($this->provider, 'staff')
        ->get('/admin/resellers')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->component('Admin/Resellers/Index')
            ->has('resellers', 1)
            ->where('resellers.0.name', $reseller->name)
            ->where('resellers.0.customers', 0)
            // Absence is a refusal: a new reseller may sell nothing, and the
            // screen says zero rather than implying the whole catalogue.
            ->where('resellers.0.products', 0)
            ->where('resellers.0.balances', []));
});

it('creates the reseller and the person who will run it, in one step', function (): void {
    $this->actingAs($this->provider, 'staff')
        ->post('/admin/resellers', [
            'name' => 'Aegean Hosting',
            'owner_name' => 'Deniz Yılmaz',
            'owner_email' => 'deniz@aegean.test',
        ])
        ->assertRedirect()
        ->assertSessionHas('status');

    $reseller = Organization::query()
        ->where('type', OrganizationType::Reseller->value)
        ->where('name', 'Aegean Hosting')
        ->sole();

    // A reseller organization with nobody in it is a node somebody has to
    // remember to come back to.
    $owner = app(OrganizationContext::class)->runAs(
        $reseller->id,
        fn (): StaffUser => StaffUser::query()->where('email', 'deniz@aegean.test')->sole(),
    );

    expect($owner->organization_id)->toBe($reseller->id);
    // Administrator of their own subtree, never super-admin: a reseller that
    // bypassed permission checks would be a reseller outside the boundary
    // that makes resale safe.
    expect($owner->fresh()->isSuperAdmin())->toBeFalse();
});

it('refuses an owner address that already works for somebody else', function (): void {
    makeReseller(email: 'taken@example.test');

    $this->actingAs($this->provider, 'staff')
        ->from('/admin/resellers/create')
        ->post('/admin/resellers', [
            'name' => 'Second Hosting',
            'owner_name' => 'Somebody',
            'owner_email' => 'taken@example.test',
        ])
        // A field error, not an error page: the operator typed the address
        // and the address is the thing that is wrong.
        ->assertSessionHasErrors('owner_email');

    expect(Organization::query()->where('name', 'Second Hosting')->exists())->toBeFalse();
});

it('shows the whole catalogue, not only what is already allowed', function (): void {
    $reseller = makeReseller()['organization'];

    Product::factory()->create(['name' => 'Reseller Hosting']);

    $this->actingAs($this->provider, 'staff')
        ->get("/admin/resellers/{$reseller->id}")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->component('Admin/Resellers/Show')
            // Both products, because this is the screen where somebody
            // decides what to allow and a list of what is allowed cannot be
            // used to allow anything new.
            ->has('catalogue', 2)
            ->where('catalogue.0.isEnabled', false)
            ->where('catalogue.0.marginPercent', null));
});

it('saves what a reseller may sell, and the margin with it', function (): void {
    $reseller = makeReseller()['organization'];

    $this->actingAs($this->provider, 'staff')
        ->from("/admin/resellers/{$reseller->id}")
        ->post("/admin/resellers/{$reseller->id}/availability", [
            'product_id' => $this->product->id,
            'is_enabled' => true,
            'margin_percent' => '15.5',
        ])
        ->assertRedirect("/admin/resellers/{$reseller->id}")
        ->assertSessionHas('status');

    $row = app(OrganizationContext::class)->runAs(
        $reseller->id,
        fn (): ResellerProduct => ResellerProduct::query()->sole(),
    );

    // The row belongs to the reseller, which is what makes "a reseller cannot
    // see another reseller's margins" true by the same mechanism as
    // everything else.
    expect($row->organization_id)->toBe($reseller->id);
    expect($row->is_enabled)->toBeTrue();
    expect((string) $row->margin_percent)->toBe('15.5000');
});

/**
 * An empty margin field is "the provider's price". Zero is somebody having
 * typed zero. Collapsing the two would make it impossible to see which was
 * meant, and a screen that shows a margin of 0% where none was set is a
 * screen lying about a decision nobody took.
 */
it('tells no margin apart from a margin of zero', function (): void {
    $reseller = makeReseller()['organization'];

    $this->actingAs($this->provider, 'staff');

    $this->post("/admin/resellers/{$reseller->id}/availability", [
        'product_id' => $this->product->id,
        'is_enabled' => true,
        'margin_percent' => null,
    ]);

    $context = app(OrganizationContext::class);

    expect($context->runAs(
        $reseller->id,
        fn (): ?string => ResellerProduct::query()->sole()->margin_percent,
    ))->toBeNull();

    $this->post("/admin/resellers/{$reseller->id}/availability", [
        'product_id' => $this->product->id,
        'is_enabled' => true,
        'margin_percent' => '0',
    ]);

    expect($context->runAs(
        $reseller->id,
        fn (): string => (string) ResellerProduct::query()->sole()->margin_percent,
    ))->toBe('0.0000');
});

it('keeps the margin when a product is turned off', function (): void {
    $reseller = makeReseller()['organization'];

    $this->actingAs($this->provider, 'staff');

    $this->post("/admin/resellers/{$reseller->id}/availability", [
        'product_id' => $this->product->id,
        'is_enabled' => true,
        'margin_percent' => '20',
    ]);

    $this->post("/admin/resellers/{$reseller->id}/availability", [
        'product_id' => $this->product->id,
        'is_enabled' => false,
        'margin_percent' => '20',
    ]);

    $row = app(OrganizationContext::class)->runAs(
        $reseller->id,
        fn (): ResellerProduct => ResellerProduct::query()->sole(),
    );

    // The row stays, which is what lets a provider turn a product off for a
    // month without losing the margin somebody agreed on the telephone.
    expect($row->is_enabled)->toBeFalse();
    expect((string) $row->margin_percent)->toBe('20.0000');
});

it('sets an exact price and removes it when it is cleared', function (): void {
    $reseller = makeReseller()['organization'];

    $this->actingAs($this->provider, 'staff');

    $this->post("/admin/resellers/{$reseller->id}/prices", [
        'product_id' => $this->product->id,
        'billing_cycle' => BillingCycle::Monthly->value,
        'currency_code' => 'eur',
        'recurring_minor' => 2499,
        'setup_minor' => 500,
    ])->assertRedirect();

    $context = app(OrganizationContext::class);

    $price = $context->runAs(
        $reseller->id,
        fn (): ResellerPrice => ResellerPrice::query()->sole(),
    );

    // Upper-cased on the way in: a currency is an ISO code, not whatever
    // case the form was filled in.
    expect($price->currency_code)->toBe('EUR');
    expect($price->recurring_minor)->toBe(2499);

    // Clearing is removing the row. Zero would mean free, which is a price.
    $this->post("/admin/resellers/{$reseller->id}/prices", [
        'product_id' => $this->product->id,
        'billing_cycle' => BillingCycle::Monthly->value,
        'currency_code' => 'EUR',
        'recurring_minor' => null,
    ])->assertRedirect();

    expect($context->runAs(
        $reseller->id,
        fn (): int => ResellerPrice::query()->count(),
    ))->toBe(0);
});

it('records a movement on the account and says what the balance became', function (): void {
    $reseller = makeReseller()['organization'];

    $this->actingAs($this->provider, 'staff')
        ->from("/admin/resellers/{$reseller->id}")
        ->post("/admin/resellers/{$reseller->id}/ledger", [
            'kind' => ResellerLedgerKind::Payment->value,
            'currency_code' => 'EUR',
            'amount_minor' => 50000,
            'description' => 'Bank transfer',
        ])
        ->assertRedirect("/admin/resellers/{$reseller->id}")
        ->assertSessionHas('status');

    $row = app(OrganizationContext::class)->runAs(
        $reseller->id,
        fn (): ResellerLedgerEntry => ResellerLedgerEntry::query()->sole(),
    );

    expect($row->balance_minor)->toBe(50000);
    // The person as words, because a statement is read by people and an id in
    // that column is a lookup nobody will do.
    expect($row->recorded_by)->toBe($this->provider->name);
});

/**
 * The form offers no sign, and the endpoint accepts none. A payment recorded
 * as a negative number is a ledger that lies twice.
 */
it('refuses an amount that is not positive', function (): void {
    $reseller = makeReseller()['organization'];

    $this->actingAs($this->provider, 'staff')
        ->from("/admin/resellers/{$reseller->id}")
        ->post("/admin/resellers/{$reseller->id}/ledger", [
            'kind' => ResellerLedgerKind::Payment->value,
            'currency_code' => 'EUR',
            'amount_minor' => -100,
        ])
        ->assertSessionHasErrors('amount_minor');
});

/**
 * A customer is inside the provider's boundary too, and a customer with a
 * margin page would be nonsense. Missing rather than forbidden, because a 403
 * confirms the record exists.
 */
it('answers 404 for an organization that is not a reseller', function (): void {
    $customer = Customer::factory()->create();

    $this->actingAs($this->provider, 'staff')
        ->get("/admin/resellers/{$customer->organization_id}")
        ->assertNotFound();
});

/**
 * The reason `resellers.administer` is a gate rather than a permission. This
 * reseller's owner is an Administrator and therefore holds
 * `organizations.manage` — and must still reach none of this.
 */
it('keeps a reseller out of the reseller programme, permissions and all', function (): void {
    $mine = makeReseller();
    $theirs = makeReseller('Aegean Hosting', 'owner@aegean.test');

    $operator = $mine['owner'];
    $operator->assignRole(SystemRole::Administrator);
    $operator = $operator->fresh();

    expect($operator->effectivePermissions())->toContain('organizations.manage');

    $this->actingAs($operator, 'staff');

    $this->get('/admin/resellers')->assertForbidden();
    $this->get('/admin/resellers/create')->assertForbidden();
    // Their own page, not somebody else's: even the reseller's own margins
    // are the provider's to set.
    $this->get("/admin/resellers/{$mine['organization']->id}")->assertForbidden();
    $this->get("/admin/resellers/{$theirs['organization']->id}")->assertForbidden();

    $this->post("/admin/resellers/{$mine['organization']->id}/ledger", [
        'kind' => ResellerLedgerKind::Credit->value,
        'currency_code' => 'EUR',
        'amount_minor' => 100000,
    ])->assertForbidden();

    expect(app(OrganizationContext::class)->runAs(
        $mine['organization']->id,
        fn (): int => ResellerLedgerEntry::query()->count(),
    ))->toBe(0);
});

it('keeps staff without organizations.manage out too', function (): void {
    $support = StaffUser::factory()->create();
    $support->assignRole(SystemRole::Support);

    expect($support->fresh()->effectivePermissions())->not->toContain('organizations.manage');

    $this->actingAs($support->fresh(), 'staff')
        ->get('/admin/resellers')
        ->assertForbidden();
});

/**
 * More than one of everything on purpose: Laravel's strict mode only reports
 * a lazy load when a query returned more than one row, so a screen that is
 * correct with one product and throws with two passes every test written
 * against a single fixture.
 */
it('draws the reseller page with several of everything', function (): void {
    $reseller = makeReseller()['organization'];

    Product::factory()->count(2)->create();

    $context = app(OrganizationContext::class);

    $context->runAs($reseller->id, function () use ($reseller): void {
        foreach (Product::query()->withoutGlobalScope('organization')->get() as $product) {
            ResellerProduct::factory()->forReseller($reseller)->forProduct($product)->create();
            ResellerPrice::factory()->forReseller($reseller)->forProduct($product)->create();
        }

        ResellerLedgerEntry::factory()->forReseller($reseller)->count(2)->create();
    });

    // Two staff, so the "who runs it" list is more than one row as well.
    $context->runAs(
        $reseller->id,
        fn (): StaffUser => StaffUser::factory()->create(['organization_id' => $reseller->id]),
    );

    $this->actingAs($this->provider, 'staff')
        ->get("/admin/resellers/{$reseller->id}")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->has('catalogue', 3)
            ->has('prices', 3)
            ->has('statement', 2)
            ->has('reseller.staff', 2));
});
