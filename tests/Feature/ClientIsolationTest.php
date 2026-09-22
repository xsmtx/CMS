<?php

declare(strict_types=1);

use App\Application\Access\SyncPermissions;
use App\Domain\Access\PermissionRegistry;
use App\Domain\Access\SystemRole;
use App\Domain\Billing\InvoiceStatus;
use App\Domain\Ordering\OrderStatus;
use App\Infrastructure\Billing\Models\Invoice;
use App\Infrastructure\Crm\Models\Customer;
use App\Infrastructure\Identity\Models\Contact;
use App\Infrastructure\Ordering\Models\Order;
use App\Infrastructure\Organizations\Models\Organization;
use Database\Seeders\ProviderOrganizationSeeder;
use Database\Seeders\SystemRoleSeeder;
use Illuminate\Support\Facades\Route;

/**
 * One sweep over every client route, rather than a test per screen.
 *
 * Two questions, asked of all of them: does a signed-out visitor get in,
 * and does a record belonging to somebody else answer 404. A route added
 * later without going through `CurrentCustomer` fails here without anybody
 * remembering to write a test for it.
 */
beforeEach(function (): void {
    $this->withoutVite();

    $this->seed(ProviderOrganizationSeeder::class);
    app(SyncPermissions::class)->handle(app(PermissionRegistry::class));
    $this->seed(SystemRoleSeeder::class);

    $this->customer = Customer::factory()->create();
    $this->owner = Contact::factory()->forCustomer($this->customer)->primary()->create();
    $this->owner->assignRole(SystemRole::AccountOwner);
    $this->owner = $this->owner->fresh();
});

/**
 * Every GET the client area answers, taken from the router rather than
 * from a list somebody has to remember to extend.
 *
 * @return list<string>
 */
function clientGetRoutes(): array
{
    $paths = [];

    foreach (Route::getRoutes() as $route) {
        $uri = $route->uri();

        if (! str_starts_with($uri, 'client') || ! in_array('GET', $route->methods(), strict: true)) {
            continue;
        }

        $paths[] = '/'.$uri;
    }

    return array_values(array_unique($paths));
}

it('sends a visitor who is not signed in to the login page', function (): void {
    foreach (clientGetRoutes() as $path) {
        // Parameters do not matter: the guard runs before anything is
        // resolved, which is the point of asserting it here.
        $concrete = preg_replace('/\{[^}]+\}/', 'anything', $path) ?? $path;

        $this->get($concrete)->assertRedirect('/login');
    }
});

it('answers 404 for every record belonging to somebody else', function (): void {
    $stranger = Customer::factory()->create();

    $invoice = Invoice::factory()->forCustomer($stranger)->status(InvoiceStatus::Unpaid)->create();
    $order = Order::factory()->create([
        'organization_id' => $stranger->organization_id,
        'customer_id' => $stranger->id,
        'status' => OrderStatus::AwaitingPayment->value,
    ]);

    $this->actingAs($this->owner, 'client')
        ->get("/client/billing/invoices/{$invoice->number}")
        ->assertNotFound();

    $this->actingAs($this->owner, 'client')
        ->get("/client/orders/{$order->number}")
        ->assertNotFound();
});

it('answers 404 for a record in another organization entirely', function (): void {
    // A reseller's customer, two branches away. The boundary and the
    // ownership check are different questions; both have to say no.
    $reseller = Organization::query()->create([
        'parent_id' => Organization::query()->withoutGlobalScope('organization')->whereNull('parent_id')->sole()->id,
        'type' => 'reseller',
        'name' => 'Reseller',
        'slug' => 'reseller-isolation',
        'is_active' => true,
    ]);

    $theirs = Customer::factory()->forOrganization($reseller)->create();
    $invoice = Invoice::factory()->forCustomer($theirs)->status(InvoiceStatus::Unpaid)->create();

    $this->actingAs($this->owner, 'client')
        ->get("/client/billing/invoices/{$invoice->number}")
        ->assertNotFound();
});
