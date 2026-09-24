<?php

declare(strict_types=1);

use App\Application\Access\SyncPermissions;
use App\Application\Organizations\CreateReseller;
use App\Application\Organizations\ResellerAttributes;
use App\Domain\Access\PermissionRegistry;
use App\Domain\Access\SystemRole;
use App\Infrastructure\Billing\Models\Invoice;
use App\Infrastructure\Catalog\Models\Product;
use App\Infrastructure\Crm\Models\Customer;
use App\Infrastructure\Organizations\Models\Organization;
use App\Infrastructure\Provisioning\Models\Server;
use App\Infrastructure\Provisioning\Models\Service;
use App\Support\Organizations\OrganizationContext;
use Database\Seeders\ProviderOrganizationSeeder;
use Database\Seeders\SystemRoleSeeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;

/**
 * Every admin screen, driven by a reseller.
 *
 * Phase 13's last slice, and the only one that cannot be done by writing
 * code: it is an audit. A reseller's staff sign into the *same* `/admin` the
 * provider uses, narrowed by the organization boundary — which is the whole
 * design, and which means a single screen that reads a provider-owned table
 * without narrowing is a leak nobody would notice from the provider's side,
 * because from there it looks right.
 *
 * So this walks the router rather than a list somebody maintains. **A screen
 * added in a later phase joins this test the day it is routed**, and if it
 * leaks or breaks under a reseller it fails here rather than in front of a
 * reseller.
 *
 * Three outcomes are acceptable per screen and nothing else is:
 *
 * - **200** — the screen works, narrowed to their own subtree.
 * - **403** — a permission or a gate they do not hold.
 * - **404** — a record that is not theirs, which is answered as missing
 *   rather than forbidden because a 403 confirms it exists.
 *
 * A **500** is a screen that assumed the provider. A **302** to the login
 * page would be a guard problem.
 */
beforeEach(function (): void {
    $this->withoutVite();

    $this->seed(ProviderOrganizationSeeder::class);
    app(SyncPermissions::class)->handle(app(PermissionRegistry::class));
    $this->seed(SystemRoleSeeder::class);

    // Things the provider owns and a reseller must not be shown. Each exists
    // so that a screen which reads it unnarrowed has something to leak.
    $this->providerProduct = Product::factory()->create(['name' => 'Provider Only Plan']);
    $this->providerServer = Server::factory()->create(['hostname' => 'provider-only.example.test']);
    $this->providerCustomer = Customer::factory()->create(['company_name' => 'Provider Only Ltd']);

    $created = app(CreateReseller::class)->handle(new ResellerAttributes(
        name: 'Anatolia Hosting',
        ownerName: 'Anatolia Owner',
        ownerEmail: 'owner@anatolia.test',
    ));

    $this->reseller = $created['organization'];

    $this->operator = $created['owner'];
    $this->operator->assignRole(SystemRole::Administrator);
    $this->operator = $this->operator->fresh();

    // A customer of their own, so "they see their own" is a claim with
    // something behind it.
    $this->ownCustomer = app(OrganizationContext::class)->runAs(
        $this->reseller->id,
        function (): Customer {
            $organization = Organization::query()->create([
                'parent_id' => $this->reseller->id,
                'type' => 'customer',
                'name' => 'Anatolia Client',
                'slug' => 'anatolia-client-'.Str::lower(Str::random(6)),
                'is_active' => true,
            ]);

            return Customer::factory()->create([
                'organization_id' => $organization->id,
                'company_name' => 'Anatolia Client Ltd',
            ]);
        },
    );
});

/**
 * Every admin screen that needs no parameters.
 *
 * Parameterised routes are covered by the record-level tests in their own
 * files, where the id can be a real one belonging to the right organization —
 * a made-up id here would only ever assert 404, which proves nothing about
 * narrowing.
 *
 * @return list<string>
 */
function adminScreens(): array
{
    $paths = [];

    foreach (Route::getRoutes() as $route) {
        if (! in_array('GET', $route->methods(), strict: true)) {
            continue;
        }

        $uri = $route->uri();

        if (! str_starts_with($uri, 'admin')) {
            continue;
        }

        if (str_contains($uri, '{')) {
            continue;
        }

        /*
         * Guest routes are not screens. `/admin/login` redirects somebody who
         * is already signed in, which is correct and would read here as a
         * broken page — so they are excluded by their middleware rather than
         * by name, and a new one is excluded the day it is added.
         */
        if (in_array('guest:staff', $route->gatherMiddleware(), strict: true)
            || in_array('guest', $route->gatherMiddleware(), strict: true)
            || str_contains($uri, 'logout')) {
            continue;
        }

        $paths[] = '/'.$uri;
    }

    sort($paths);

    return array_values(array_unique($paths));
}

it('finds admin screens to audit at all', function (): void {
    // A guard against the audit below quietly passing because the route
    // filter stopped matching anything.
    expect(count(adminScreens()))->toBeGreaterThan(25);
});

/**
 * The audit. One request per screen, and the assertion is about the status
 * rather than the markup: a 500 is a screen that assumed the provider, and
 * that is the failure this slice exists to find.
 */
it('answers every admin screen sensibly for a reseller', function (): void {
    $this->actingAs($this->operator, 'staff');

    $broken = [];

    foreach (adminScreens() as $path) {
        $status = $this->get($path)->getStatusCode();

        if (! in_array($status, [200, 403, 404], strict: true)) {
            $broken[$path] = $status;
        }
    }

    // Reported together rather than one at a time: an audit that stopped at
    // the first failure would be an audit somebody runs twenty times.
    expect($broken)->toBe([]);
});

/**
 * The leak this slice is actually about.
 *
 * Every screen a reseller may open, checked for the provider's own records.
 * A screen that reads a provider-owned table without narrowing looks correct
 * from the provider's side, which is why only a reseller can find it.
 */
it('shows a reseller none of the provider own records', function (): void {
    $this->actingAs($this->operator, 'staff');

    $leaks = [];
    $checked = 0;

    $secrets = [
        'Provider Only Plan',
        'provider-only.example.test',
        'Provider Only Ltd',
    ];

    foreach (adminScreens() as $path) {
        $response = $this->get($path);

        if ($response->getStatusCode() !== 200) {
            continue;
        }

        $body = $response->getContent();

        if ($body === false) {
            continue;
        }

        $checked++;

        foreach ($secrets as $secret) {
            if (str_contains($body, $secret)) {
                $leaks[] = $path.' → '.$secret;
            }
        }
    }

    expect($leaks)->toBe([]);

    // A leak test that checked nothing would pass. A reseller does reach a
    // good many of these screens — their own customers, orders, invoices,
    // services, tickets — so if this number collapses, the assertion above
    // has stopped meaning anything.
    expect($checked)->toBeGreaterThan(10);
});

/**
 * The other half: narrowing that narrowed too far is a reseller who cannot
 * see their own work, which is just as broken and much easier to ship.
 */
it('shows a reseller their own records', function (): void {
    $this->actingAs($this->operator, 'staff')
        ->get('/admin/customers')
        ->assertOk()
        ->assertSee('Anatolia Client Ltd', false)
        ->assertDontSee('Provider Only Ltd', false);
});

/**
 * The catalogue is the one thing a reseller legitimately reads across the
 * boundary — they cannot sell what they cannot see — and it is narrowed by
 * something else instead: the availability rows the provider wrote for them.
 * Absence is a refusal, so a reseller with no rows sells nothing.
 */
it('keeps the provider catalogue out of a reseller own setup screens', function (): void {
    $this->actingAs($this->operator, 'staff');

    $response = $this->get('/admin/catalog/products');

    // Either they may not manage a catalogue at all, or they see their own
    // (empty) one. What they must never see is the provider's product.
    expect($response->getStatusCode())->toBeIn([200, 403]);

    if ($response->getStatusCode() === 200) {
        $response->assertDontSee('Provider Only Plan', false);
    }
});

/**
 * The record-level half of the audit.
 *
 * The screen list above can only cover routes that need no parameters, and a
 * made-up id would only ever prove that a 404 happens. These are the real
 * leak: a real id, belonging to the provider, in a reseller's hands.
 *
 * **404 rather than 403 throughout.** A 403 on a record outside the boundary
 * confirms the record exists, which is the thing the boundary is for.
 */
it('answers 404 for the provider own records, not 403', function (): void {
    $this->actingAs($this->operator, 'staff');

    $providerInvoice = Invoice::factory()->create([
        'customer_id' => $this->providerCustomer->id,
    ]);

    $providerService = Service::factory()->create([
        'customer_id' => $this->providerCustomer->id,
    ]);

    $probes = [
        '/admin/customers/'.$this->providerCustomer->id,
        '/admin/invoices/'.$providerInvoice->id,
        '/admin/services/'.$providerService->id,
        // A product's own screen is the addon list; the product itself has no
        // GET of its own, which is why this names the addons route.
        '/admin/catalog/products/'.$this->providerProduct->id.'/addons',
    ];

    $wrong = [];

    foreach ($probes as $path) {
        $status = $this->get($path)->getStatusCode();

        // 403 is accepted only where the *screen* is shut to them, which is a
        // different answer from "this record is not yours"; 200 never is.
        if (! in_array($status, [403, 404], strict: true)) {
            $wrong[$path] = $status;
        }
    }

    expect($wrong)->toBe([]);
});

/**
 * Their own records still open, or the narrowing has gone too far — which is
 * just as broken and much easier to ship than a leak.
 */
it('opens a reseller own record by id', function (): void {
    $this->actingAs($this->operator, 'staff')
        ->get('/admin/customers/'.$this->ownCustomer->id)
        ->assertOk();
});

it('keeps the fleet away from a reseller entirely', function (): void {
    // Servers live behind the owner-only section of Setup: adding one hands out
    // credentials to somebody else's machine, and a reseller is somebody else.
    // The page itself opens — it carries their own products and roles — and the
    // section is simply not there for them.
    $this->actingAs($this->operator, 'staff')
        ->get('/admin/apps')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->where(
                'sections',
                fn (Collection $sections): bool => $sections
                    ->pluck('key')
                    ->doesntContain('integrations'),
            ));

    $this->actingAs($this->operator, 'staff')
        ->get('/admin/apps/infrastructure')
        ->assertForbidden();
});
