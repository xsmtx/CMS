<?php

declare(strict_types=1);

use App\Application\Notifications\DeepLink;
use App\Domain\Access\SystemRole;
use App\Domain\Billing\InvoiceStatus;
use App\Domain\Organizations\OrganizationType;
use App\Domain\Provisioning\ServiceStatus;
use App\Infrastructure\Billing\Models\Invoice;
use App\Infrastructure\Crm\Models\Customer;
use App\Infrastructure\Domains\Models\Domain;
use App\Infrastructure\Identity\Models\Contact;
use App\Infrastructure\Identity\Models\StaffUser;
use App\Infrastructure\Ordering\Models\Order;
use App\Infrastructure\Organizations\Models\Organization;
use App\Infrastructure\Provisioning\Models\Service;
use App\Infrastructure\Support\Models\Ticket;
use App\Support\Organizations\OrganizationContext;
use Database\Seeders\ProviderOrganizationSeeder;
use Database\Seeders\SystemRoleSeeder;

/**
 * Every link a message's button lands on (§26).
 *
 * **This is the test the bug it exists for could not have failed without.**
 * `SendEventNotifications` built `/client/orders/{ulid}` for a route that
 * looks an order up by its **number**, so the first message this platform
 * ever sends a customer — their order confirmation — arrived with a link that
 * answered 404. Everything passed: the message was sent, the delivery row was
 * written, and the string inside it was never asked to resolve.
 *
 * So these do not assert the shape of a URL. They **open** it, as the person
 * who would have received it, and expect the page. A path that exists with
 * the wrong key in it still 404s, which is the whole failure.
 *
 * The other half of the same mistake is the one that leaks rather than 404s:
 * a customer handed an `/admin` link. Both areas are driven here.
 */
beforeEach(function (): void {
    $this->seed(ProviderOrganizationSeeder::class);
    $this->seed(SystemRoleSeeder::class);

    $this->provider = Organization::query()
        ->where('type', OrganizationType::Provider->value)
        ->firstOrFail();

    app(OrganizationContext::class)->set($this->provider->id);

    $this->links = app(DeepLink::class);
});

/** A customer with a portal account, which is what a link is opened by. */
function linkCustomer(): Contact
{
    $contact = app(OrganizationContext::class)->withoutBoundary(static function (): Contact {
        $customer = Customer::factory()->create();

        return Contact::factory()
            ->forCustomer($customer)
            ->primary()
            ->create();
    });

    // The role, or every one of these drives a 403 and proves nothing —
    // `assertOk()` would then be failing for the right reason by accident,
    // and `assertSessionHasNoErrors()` would have passed against it.
    $contact->assignRole(SystemRole::AccountOwner);

    return $contact->fresh() ?? $contact;
}

/** The path out of an absolute URL, which is what a test client wants. */
function pathOf(string $url): string
{
    return (string) parse_url($url, PHP_URL_PATH);
}

it('opens the order link a customer is sent', function (): void {
    $contact = linkCustomer();

    $order = app(OrganizationContext::class)->withoutBoundary(
        static fn (): Order => Order::factory()->create([
            'organization_id' => $contact->customer->organization_id,
            'customer_id' => $contact->customer_id,
        ]),
    );

    // The bug in one line: the route binds on `number` and the listener sent
    // the id, so this was a 404 in the first email anybody ever receives.
    expect(pathOf($this->links->order($order)))->toContain($order->number)
        ->and(pathOf($this->links->order($order)))->not->toContain($order->id);

    $this->actingAs($contact, 'client')
        ->get(pathOf($this->links->order($order)))
        ->assertOk();
});

it('opens the invoice link a customer is sent', function (): void {
    $contact = linkCustomer();

    $invoice = app(OrganizationContext::class)->withoutBoundary(
        static fn (): Invoice => Invoice::factory()
            ->forCustomer($contact->customer)
            ->status(InvoiceStatus::Unpaid)
            ->create(),
    );

    $this->actingAs($contact, 'client')
        ->get(pathOf($this->links->invoice($invoice)))
        ->assertOk();
});

it('opens the service link a customer is sent', function (): void {
    $contact = linkCustomer();

    $service = app(OrganizationContext::class)->withoutBoundary(
        static fn (): Service => Service::factory()->create([
            'organization_id' => $contact->customer->organization_id,
            'customer_id' => $contact->customer_id,
            'status' => ServiceStatus::Active->value,
        ]),
    );

    $this->actingAs($contact, 'client')
        ->get(pathOf($this->links->service($service)))
        ->assertOk();
});

it('opens the domain link a customer is sent', function (): void {
    $contact = linkCustomer();

    $domain = app(OrganizationContext::class)->withoutBoundary(
        static fn (): Domain => Domain::factory()->create([
            'organization_id' => $contact->customer->organization_id,
            'customer_id' => $contact->customer_id,
        ]),
    );

    $this->actingAs($contact, 'client')
        ->get(pathOf($this->links->domain($domain)))
        ->assertOk();
});

it('sends each side of a ticket to its own area', function (): void {
    $contact = linkCustomer();

    $ticket = app(OrganizationContext::class)->withoutBoundary(
        static fn (): Ticket => Ticket::factory()->create([
            'organization_id' => $contact->customer->organization_id,
            'customer_id' => $contact->customer_id,
            'contact_id' => $contact->id,
        ]),
    );

    // The half that leaks rather than 404s: a customer handed an /admin URL.
    expect(pathOf($this->links->customerTicket($ticket)))->toStartWith('/client/')
        ->and(pathOf($this->links->staffTicket($ticket)))->toStartWith('/admin/');

    $this->actingAs($contact, 'client')
        ->get(pathOf($this->links->customerTicket($ticket)))
        ->assertOk();

    $staff = StaffUser::factory()->create();
    $staff->assignRole(SystemRole::Administrator);

    $this->actingAs($staff->fresh(), 'staff')
        ->get(pathOf($this->links->staffTicket($ticket)))
        ->assertOk();
});

it('opens the alerts link an operator is woken by', function (): void {
    $staff = StaffUser::factory()->create();
    $staff->assignRole(SystemRole::Administrator);

    $this->actingAs($staff->fresh(), 'staff')
        ->get(pathOf($this->links->alerts()))
        ->assertOk();
});

/** The status page needs no session at all, which is the point of it. */
it('opens the status link with nobody signed in', function (): void {
    $this->get(pathOf($this->links->status()))->assertOk();
});

/**
 * An audit that checks nothing passes. Both halves: the listeners build their
 * links here rather than by hand, and this file actually exercised some.
 */
it('is the only place a notification link is built', function (): void {
    $offences = [];

    foreach (glob(app_path('Application/Notifications/Listeners/*.php')) ?: [] as $file) {
        $contents = (string) file_get_contents($file);

        if (preg_match("#url\\('/(client|admin)#", $contents) === 1) {
            $offences[] = basename($file);
        }
    }

    expect($offences)->toBe([], implode(', ', $offences));
});
