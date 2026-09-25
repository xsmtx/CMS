<?php

declare(strict_types=1);

use App\Domain\Billing\InvoiceStatus;
use App\Domain\Billing\PaymentStatus;
use App\Infrastructure\Billing\GatewayRegistry;
use App\Infrastructure\Billing\Models\GatewayEventRecord;
use App\Infrastructure\Billing\Models\Invoice;
use App\Infrastructure\Billing\Models\Payment;
use App\Infrastructure\Billing\Models\Transaction;
use App\Infrastructure\Crm\Models\Customer;
use App\Infrastructure\Organizations\Models\Organization;
use Illuminate\Testing\TestResponse;
use InfraCMS\GatewayStripe\StripeGateway;

beforeEach(function (): void {
    // The adapter lives in a package now; this puts its classes on the
    // autoloader without installing or enabling anything.
    loadModuleClasses('gateway-stripe');

    $this->secret = 'whsec_test_secret';

    // A registry holding a real Stripe adapter pointed at a faked API, so
    // the signature scheme and the payload mapping are exercised rather
    // than mocked away.
    $registry = new GatewayRegistry;
    $registry->register(new StripeGateway(
        secret: 'sk_test',
        webhookSecret: $this->secret,
        apiBase: 'https://api.stripe.test',
    ));

    $this->app->instance(GatewayRegistry::class, $registry);

    $this->provider = Organization::factory()->provider()->create();
    $this->customer = Customer::factory()->forOrganization($this->provider)->create();

    $this->invoice = Invoice::factory()->forCustomer($this->customer)->create([
        'currency_code' => 'EUR',
        'total_minor' => 1998,
        'status' => InvoiceStatus::Unpaid->value,
    ]);

    $this->payment = Payment::factory()->forInvoice($this->invoice)->create([
        'gateway' => 'stripe',
        'status' => PaymentStatus::Pending->value,
        'amount_minor' => 1998,
        'reference' => 'pi_test_123',
        'received_at' => null,
    ]);
});

function stripePayload(string $id, string $type, array $object = []): string
{
    return (string) json_encode([
        'id' => $id,
        'type' => $type,
        'data' => ['object' => [
            'id' => 'pi_test_123',
            'amount' => 1998,
            'currency' => 'eur',
            ...$object,
        ]],
    ]);
}

function stripeSignature(string $body, string $secret, ?int $timestamp = null): string
{
    $timestamp ??= time();

    return 't='.$timestamp.',v1='.hash_hmac('sha256', $timestamp.'.'.$body, $secret);
}

function deliver(string $body, ?string $signature = null, string $gateway = 'stripe'): TestResponse
{
    return test()->call(
        'POST',
        "/api/webhooks/payments/{$gateway}",
        [],
        [],
        [],
        $signature === null ? [] : ['HTTP_STRIPE_SIGNATURE' => $signature],
        $body,
    );
}

it('settles an invoice on a verified payment event', function (): void {
    $body = stripePayload('evt_1', 'payment_intent.succeeded');

    deliver($body, stripeSignature($body, $this->secret))->assertOk();

    expect($this->payment->fresh()?->status)->toBe(PaymentStatus::Completed)
        ->and($this->invoice->fresh()?->status)->toBe(InvoiceStatus::Paid)
        ->and($this->invoice->fresh()?->paid->toDecimalString())->toBe('19.98');
});

it('produces one payment when the same event is delivered five times', function (): void {
    $body = stripePayload('evt_repeat', 'payment_intent.succeeded');
    $signature = stripeSignature($body, $this->secret);

    for ($attempt = 0; $attempt < 5; $attempt++) {
        deliver($body, $signature)->assertOk();
    }

    // The unique index on (gateway, event id) is what guarantees this; a
    // check-then-write would race.
    expect(GatewayEventRecord::query()->withoutGlobalScope('organization')->count())->toBe(1)
        ->and(Transaction::query()->withoutGlobalScope('organization')->count())->toBe(1)
        ->and($this->invoice->fresh()?->paid->toDecimalString())->toBe('19.98');
});

it('does not settle an invoice twice when a second event id repeats the news', function (): void {
    foreach (['evt_a', 'evt_b'] as $id) {
        $body = stripePayload($id, 'payment_intent.succeeded');
        deliver($body, stripeSignature($body, $this->secret))->assertOk();
    }

    expect(Transaction::query()->withoutGlobalScope('organization')->count())->toBe(1)
        ->and($this->invoice->fresh()?->paid->toDecimalString())->toBe('19.98');
});

it('refuses a payload with no signature', function (): void {
    $body = stripePayload('evt_unsigned', 'payment_intent.succeeded');

    deliver($body)->assertOk();

    expect($this->payment->fresh()?->status)->toBe(PaymentStatus::Pending)
        ->and($this->invoice->fresh()?->status)->toBe(InvoiceStatus::Unpaid);
});

it('refuses a payload signed with the wrong secret', function (): void {
    $body = stripePayload('evt_forged', 'payment_intent.succeeded');

    deliver($body, stripeSignature($body, 'whsec_not_ours'))->assertOk();

    expect($this->payment->fresh()?->status)->toBe(PaymentStatus::Pending);
});

it('refuses a payload whose body was changed after signing', function (): void {
    $body = stripePayload('evt_tampered', 'payment_intent.succeeded');
    $signature = stripeSignature($body, $this->secret);

    $tampered = str_replace('1998', '199800', $body);

    deliver($tampered, $signature)->assertOk();

    expect($this->payment->fresh()?->status)->toBe(PaymentStatus::Pending);
});

it('refuses a replayed payload from outside the tolerance window', function (): void {
    // The signature is valid forever; the timestamp is what stops last
    // month's payload being accepted today.
    $body = stripePayload('evt_old', 'payment_intent.succeeded');

    deliver($body, stripeSignature($body, $this->secret, time() - 86400))->assertOk();

    expect($this->payment->fresh()?->status)->toBe(PaymentStatus::Pending);
});

it('records a rejection without storing the body it could not trust', function (): void {
    $body = stripePayload('evt_bad', 'payment_intent.succeeded');

    deliver($body, stripeSignature($body, 'wrong'))->assertOk();

    $record = GatewayEventRecord::query()->withoutGlobalScope('organization')->sole();

    expect($record->outcome)->toBe('rejected')
        ->and($record->payload)->toBeNull()
        ->and($record->error)->toBe('signature_verification_failed');
});

it('marks a payment failed on a failure event', function (): void {
    $body = stripePayload('evt_fail', 'payment_intent.payment_failed', [
        'last_payment_error' => ['message' => 'Your card was declined.'],
    ]);

    deliver($body, stripeSignature($body, $this->secret))->assertOk();

    $fresh = $this->payment->fresh();

    expect($fresh?->status)->toBe(PaymentStatus::Failed)
        ->and($fresh?->failure_reason)->toBe('Your card was declined.')
        // Nothing was paid, so the invoice has not moved.
        ->and($this->invoice->fresh()?->status)->toBe(InvoiceStatus::Unpaid);
});

it('does not fail a payment that already succeeded', function (): void {
    $success = stripePayload('evt_ok', 'payment_intent.succeeded');
    deliver($success, stripeSignature($success, $this->secret));

    $failure = stripePayload('evt_late_failure', 'payment_intent.payment_failed');
    deliver($failure, stripeSignature($failure, $this->secret));

    expect($this->payment->fresh()?->status)->toBe(PaymentStatus::Completed)
        ->and($this->invoice->fresh()?->status)->toBe(InvoiceStatus::Paid);
});

it('records an event it cannot match to a payment', function (): void {
    $body = (string) json_encode([
        'id' => 'evt_stranger',
        'type' => 'payment_intent.succeeded',
        'data' => ['object' => ['id' => 'pi_not_ours', 'amount' => 100, 'currency' => 'eur']],
    ]);

    deliver($body, stripeSignature($body, $this->secret))->assertOk();

    expect(GatewayEventRecord::query()->withoutGlobalScope('organization')->sole()->outcome)
        ->toBe('unmatched');
});

it('records an event type it does not act on', function (): void {
    $body = stripePayload('evt_noise', 'customer.subscription.updated');

    deliver($body, stripeSignature($body, $this->secret))->assertOk();

    expect(GatewayEventRecord::query()->withoutGlobalScope('organization')->sole()->outcome)
        ->toBe('ignored');
});

it('answers 404 for a gateway this installation does not have', function (): void {
    $body = stripePayload('evt_x', 'payment_intent.succeeded');

    deliver($body, stripeSignature($body, $this->secret), 'paypal')->assertNotFound();
});
