<?php

declare(strict_types=1);

use App\Domain\Billing\InvoiceStatus;
use App\Domain\Billing\PaymentStatus;
use App\Infrastructure\Billing\GatewayRegistry;
use App\Infrastructure\Billing\Gateways\ManualGateway;
use App\Infrastructure\Billing\Models\Invoice;
use App\Infrastructure\Billing\Models\InvoiceItem;
use App\Infrastructure\Billing\Models\Payment;
use App\Infrastructure\Crm\Models\Customer;
use App\Infrastructure\Identity\Models\Contact;
use App\Infrastructure\Organizations\Models\Organization;
use Database\Seeders\ProviderOrganizationSeeder;
use Illuminate\Support\Facades\Http;
use InfraCMS\GatewayStripe\StripeGateway;

beforeEach(function (): void {
    // The adapter lives in a package now; this puts its classes on the
    // autoloader without installing or enabling anything.
    loadModuleClasses('gateway-stripe');

    $this->withoutVite();

    $this->seed(ProviderOrganizationSeeder::class);
    $this->provider = Organization::query()->withoutGlobalScope('organization')->whereNull('parent_id')->sole();

    $registry = new GatewayRegistry;
    $registry->register(new ManualGateway('Send to IBAN TR00 0000 0000'));
    $registry->register(new StripeGateway(
        secret: 'sk_test',
        webhookSecret: 'whsec_test',
        apiBase: 'https://api.stripe.test',
    ));

    $this->app->instance(GatewayRegistry::class, $registry);

    $this->customer = Customer::factory()->forOrganization($this->provider)->create();
    $this->contact = Contact::factory()->create([
        'customer_id' => $this->customer->id,
        'organization_id' => $this->customer->organization_id,
    ]);

    $this->invoice = Invoice::factory()
        ->forCustomer($this->customer)
        ->status(InvoiceStatus::Unpaid)
        ->totalling(1998)
        ->create();

    InvoiceItem::factory()->forInvoice($this->invoice)->create();
});

it('shows a customer their own invoice', function (): void {
    $this->actingAs($this->contact, 'client')
        ->get("/invoices/{$this->invoice->number}")
        ->assertOk()
        ->assertSee($this->invoice->number)
        ->assertSee(__('billing.payments.pay_now'), false);
});

it('offers the bank transfer instructions an operator configured', function (): void {
    $this->actingAs($this->contact, 'client')
        ->get("/invoices/{$this->invoice->number}")
        ->assertOk()
        ->assertSee('IBAN TR00 0000 0000');
});

it('hides an invoice belonging to somebody else', function (): void {
    $stranger = Contact::factory()->create();

    $this->actingAs($stranger, 'client')
        ->get("/invoices/{$this->invoice->number}")
        ->assertNotFound();
});

it('hides an invoice from a visitor who is not signed in', function (): void {
    // A number is short and sequential. Without either proof of identity,
    // it opens nothing.
    $this->get("/invoices/{$this->invoice->number}")->assertNotFound();
});

it('lets the browser that placed the order reach its invoice', function (): void {
    $this->withSession(['storefront.invoices' => [$this->invoice->id]])
        ->get("/invoices/{$this->invoice->number}")
        ->assertOk()
        ->assertSee($this->invoice->number);
});

it('does not let that session reach anybody elses invoice', function (): void {
    $other = Invoice::factory()
        ->forCustomer(Customer::factory()->create())
        ->status(InvoiceStatus::Unpaid)
        ->create();

    $this->withSession(['storefront.invoices' => [$this->invoice->id]])
        ->get("/invoices/{$other->number}")
        ->assertNotFound();
});

it('does not show a draft', function (): void {
    $draft = Invoice::factory()->forCustomer($this->customer)->draft()->create();

    $this->actingAs($this->contact, 'client')
        ->get("/invoices/{$draft->number}")
        ->assertNotFound();
});

it('records a pending payment when the customer chooses bank transfer', function (): void {
    $this->actingAs($this->contact, 'client')
        ->post("/invoices/{$this->invoice->number}/pay", ['gateway' => 'manual'])
        ->assertRedirect("/invoices/{$this->invoice->number}/returned");

    $payment = Payment::query()->withoutGlobalScope('organization')->sole();

    // Nothing has arrived. The invoice is untouched until someone sees the
    // transfer land.
    expect($payment->status)->toBe(PaymentStatus::Pending)
        ->and($payment->amount->minorUnits)->toBe(1998)
        ->and($this->invoice->fresh()?->status)->toBe(InvoiceStatus::Unpaid)
        ->and($this->invoice->fresh()?->paid->isZero())->toBeTrue();
});

it('sends the customer to the gateway when it asks for a redirect', function (): void {
    Http::fake([
        'api.stripe.test/v1/payment_intents' => Http::response([
            'id' => 'pi_redirect',
            'status' => 'requires_action',
            'next_action' => ['redirect_to_url' => ['url' => 'https://stripe.test/authenticate']],
        ]),
    ]);

    $this->actingAs($this->contact, 'client')
        ->post("/invoices/{$this->invoice->number}/pay", ['gateway' => 'stripe'])
        ->assertRedirect('https://stripe.test/authenticate');

    expect(Payment::query()->withoutGlobalScope('organization')->sole()->status)
        ->toBe(PaymentStatus::Pending);
});

it('marks nothing paid when the customer comes back from the gateway', function (): void {
    Http::fake([
        'api.stripe.test/v1/payment_intents' => Http::response([
            'id' => 'pi_pending',
            'status' => 'requires_action',
        ]),
    ]);

    $this->actingAs($this->contact, 'client')
        ->post("/invoices/{$this->invoice->number}/pay", ['gateway' => 'stripe']);

    // A redirect back is not proof of anything, whatever the query string
    // claims.
    $this->actingAs($this->contact, 'client')
        ->get("/invoices/{$this->invoice->number}/returned?success=true&paid=1")
        ->assertOk()
        ->assertSee(__('billing.payments.checking'), false);

    expect($this->invoice->fresh()?->status)->toBe(InvoiceStatus::Unpaid)
        ->and($this->invoice->fresh()?->paid->isZero())->toBeTrue();
});

it('believes a gateway that confirms synchronously', function (): void {
    // This answer came from a call this server made, not from a browser on
    // its way back.
    Http::fake([
        'api.stripe.test/v1/payment_intents' => Http::response([
            'id' => 'pi_done',
            'status' => 'succeeded',
        ]),
    ]);

    $this->actingAs($this->contact, 'client')
        ->post("/invoices/{$this->invoice->number}/pay", ['gateway' => 'stripe']);

    expect($this->invoice->fresh()?->status)->toBe(InvoiceStatus::Paid)
        ->and($this->invoice->fresh()?->paid->toDecimalString())->toBe('19.98');
});

it('sends an idempotency key with the charge', function (): void {
    Http::fake([
        'api.stripe.test/v1/payment_intents' => Http::response(['id' => 'pi_1', 'status' => 'processing']),
    ]);

    $this->actingAs($this->contact, 'client')
        ->post("/invoices/{$this->invoice->number}/pay", ['gateway' => 'stripe']);

    Http::assertSent(fn ($request): bool => $request->hasHeader('Idempotency-Key')
        && $request['amount'] === 1998
        && $request['currency'] === 'eur');
});

it('records the failure without charging anything when the gateway refuses', function (): void {
    Http::fake([
        'api.stripe.test/v1/payment_intents' => Http::response([
            'error' => ['message' => 'Your card was declined.'],
        ], 402),
    ]);

    $this->actingAs($this->contact, 'client')
        ->post("/invoices/{$this->invoice->number}/pay", ['gateway' => 'stripe']);

    $payment = Payment::query()->withoutGlobalScope('organization')->sole();

    expect($payment->status)->toBe(PaymentStatus::Failed)
        ->and($payment->failure_reason)->toBe('Your card was declined.')
        ->and($this->invoice->fresh()?->status)->toBe(InvoiceStatus::Unpaid);
});

it('refuses to pay an invoice that is already settled', function (): void {
    $paid = Invoice::factory()->forCustomer($this->customer)->status(InvoiceStatus::Paid)->create();

    $this->actingAs($this->contact, 'client')
        ->post("/invoices/{$paid->number}/pay", ['gateway' => 'manual'])
        ->assertStatus(412);
});
