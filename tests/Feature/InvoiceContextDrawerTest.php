<?php

declare(strict_types=1);

use App\Application\Access\SyncPermissions;
use App\Application\Organizations\CreateReseller;
use App\Application\Organizations\ResellerAttributes;
use App\Domain\Access\PermissionRegistry;
use App\Domain\Access\SystemRole;
use App\Domain\Billing\InvoiceStatus;
use App\Http\Middleware\HandleInertiaRequests;
use App\Infrastructure\Billing\Models\Invoice;
use App\Infrastructure\Billing\Models\InvoiceItem;
use App\Infrastructure\Crm\Models\Customer;
use App\Infrastructure\Identity\Models\StaffUser;
use App\Support\Organizations\OrganizationContext;
use Database\Seeders\ProviderOrganizationSeeder;
use Database\Seeders\SystemRoleSeeder;
use Illuminate\Http\Request;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;

/**
 * The context drawer's half of the list screen (§8).
 *
 * The prop is `Inertia::optional`, which is the whole reason a drawer is
 * cheaper than the navigation it replaces: on an ordinary page load the
 * server does not build it, and when the browser asks for it by name the
 * server builds one invoice instead of re-running the list.
 *
 * So there are two things worth asserting, and they are both about cost and
 * disclosure rather than about markup: the prop is absent until asked for,
 * and an id somebody typed into the query string cannot be used to find out
 * whether an invoice exists.
 */
beforeEach(function (): void {
    $this->withoutVite();

    $this->seed(ProviderOrganizationSeeder::class);
    app(SyncPermissions::class)->handle(app(PermissionRegistry::class));
    $this->seed(SystemRoleSeeder::class);

    $this->admin = StaffUser::factory()->create();
    $this->admin->assignRole(SystemRole::Administrator);
    $this->admin = $this->admin->fresh();
});

function invoiceWithLines(?Customer $customer = null, int $lines = 2): Invoice
{
    $invoice = Invoice::factory()->create([
        'customer_id' => ($customer ?? Customer::factory()->create())->id,
        'status' => InvoiceStatus::Unpaid->value,
    ]);

    InvoiceItem::factory()->count($lines)->create(['invoice_id' => $invoice->id]);

    return $invoice->fresh();
}

/**
 * A partial reload, the way the drawer makes one.
 *
 * Inertia decides whether to honour `only` from these headers, so a test that
 * left them off would be exercising a full page load and the `optional` prop
 * would never be evaluated at all.
 *
 * The answer is JSON rather than a document, which is why the assertions
 * below read `props.peek` instead of using `assertInertia` — that helper
 * reads the page object out of a rendered view, and a partial reload never
 * renders one.
 */
function askForPeek(string $id): TestResponse
{
    $request = Request::create('/admin/invoices');

    return test()->get('/admin/invoices?peek='.$id, [
        'X-Inertia' => 'true',
        // The real asset version, or Inertia answers 409 and asks the browser
        // to reload — which is the right behaviour and the wrong test.
        'X-Inertia-Version' => (string) app(HandleInertiaRequests::class)->version($request),
        'X-Inertia-Partial-Component' => 'Admin/Invoices/Index',
        'X-Inertia-Partial-Data' => 'peek',
    ]);
}

it('does not build the drawer record on an ordinary page load', function (): void {
    invoiceWithLines();

    $this->actingAs($this->admin, 'staff')
        ->get('/admin/invoices')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->component('Admin/Invoices/Index')
            // Absent, not null: a prop computed and then not used is a query
            // paid for on every page load of a screen operators live on.
            ->missing('peek'));
});

it('builds one invoice when the drawer asks for it', function (): void {
    $invoice = invoiceWithLines(lines: 3);

    $this->actingAs($this->admin, 'staff');

    askForPeek($invoice->id)
        ->assertOk()
        ->assertJsonPath('props.peek.number', $invoice->number)
        ->assertJsonCount(3, 'props.peek.items')
        // Only `peek` came back. A partial reload that re-ran the list would
        // make the drawer cost more than the navigation it replaces.
        ->assertJsonMissingPath('props.invoices')
        // The lines are there and nothing editable is: the drawer is for a
        // glance, and everything else is a reason to open the record.
        ->assertJsonMissingPath('props.peek.terms')
        ->assertJsonMissingPath('props.peek.billTo');
});

/**
 * More than one row on purpose. Laravel's strict mode only reports a lazy
 * load when the query returned more than one, so a drawer that is correct
 * with one line and throws with two passes every test written against a
 * single fixture.
 */
it('draws an invoice with several lines and a payment without lazy loading', function (): void {
    $customer = Customer::factory()->create();
    invoiceWithLines($customer);
    $invoice = invoiceWithLines($customer, lines: 4);

    $this->actingAs($this->admin, 'staff');

    askForPeek($invoice->id)
        ->assertOk()
        ->assertJsonCount(4, 'props.peek.items');
});

it('says nothing about an invoice that is not there', function (): void {
    $this->actingAs($this->admin, 'staff');

    askForPeek('01jzzzzzzzzzzzzzzzzzzzzzzz')
        ->assertOk()
        ->assertJsonPath('props.peek', null);
});

/**
 * The id is in a query string, which is something anybody can type. A 403
 * there would be confirmation that the invoice exists, so the answer is the
 * same as for an invoice that never existed.
 */
it('answers the same for another reseller\'s invoice as for a missing one', function (): void {
    $context = app(OrganizationContext::class);

    $mine = app(CreateReseller::class)->handle(new ResellerAttributes(
        name: 'Anatolia Hosting',
        ownerName: 'Anatolia Owner',
        ownerEmail: 'owner@anatolia.test',
    ));

    $theirs = app(CreateReseller::class)->handle(new ResellerAttributes(
        name: 'Aegean Hosting',
        ownerName: 'Aegean Owner',
        ownerEmail: 'owner@aegean.test',
    ));

    $operator = $mine['owner'];
    $operator->assignRole(SystemRole::Administrator);

    $hidden = $context->runAs(
        $theirs['organization']->id,
        fn (): Invoice => invoiceWithLines(Customer::factory()->create([
            'organization_id' => $theirs['organization']->id,
        ])),
    );

    $this->actingAs($operator->fresh(), 'staff');

    askForPeek($hidden->id)
        ->assertOk()
        ->assertJsonPath('props.peek', null);
});
