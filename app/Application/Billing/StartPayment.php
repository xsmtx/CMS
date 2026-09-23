<?php

declare(strict_types=1);

namespace App\Application\Billing;

use App\Application\Billing\Exceptions\PaymentRefused;
use App\Domain\Billing\Contracts\PaymentGateway;
use App\Domain\Billing\PaymentIntent;
use App\Domain\Billing\PaymentResult;
use App\Domain\Billing\PaymentStatus;
use App\Infrastructure\Billing\GatewayRegistry;
use App\Infrastructure\Billing\Models\Invoice;
use App\Infrastructure\Billing\Models\Payment;
use App\Support\Audit\Facades\Audit;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * Asks a gateway to take money for an invoice.
 *
 * The payment row is written **before** the gateway is called, as pending.
 * If the call times out or the answer is lost, there is still a record of
 * the attempt carrying the idempotency key — so a retry reaches the same
 * payment rather than charging a second time, and a webhook that arrives
 * about an answer we never saw finds something to attach to.
 *
 * Nothing here marks anything paid. A gateway that answers "succeeded"
 * synchronously is believed, because that answer came from a server-to-
 * server call we made; a redirect never is.
 */
final readonly class StartPayment
{
    public function __construct(
        private GatewayRegistry $gateways,
        private RecordPayment $payments,
    ) {}

    public function handle(Invoice $invoice, string $gatewayKey, ?string $returnUrl = null): PaymentResult
    {
        $gateway = $this->gateways->find($gatewayKey);

        if (! $gateway instanceof PaymentGateway) {
            throw PaymentRefused::currencyMismatch($invoice->currency_code, $gatewayKey);
        }

        $balance = $invoice->balance();

        if (! $balance->isPositive() || ! $invoice->status->isOwed()) {
            throw PaymentRefused::alreadyPaid();
        }

        if (! $gateway->capabilities()->supportsCurrency($invoice->currency_code)) {
            throw PaymentRefused::currencyMismatch($invoice->currency_code, $gatewayKey);
        }

        $payment = Payment::query()->create([
            'organization_id' => $invoice->organization_id,
            'invoice_id' => $invoice->id,
            'customer_id' => $invoice->customer_id,
            'gateway' => $gatewayKey,
            'status' => PaymentStatus::Pending->value,
            'currency_code' => $balance->currency->code,
            'amount_minor' => $balance->minorUnits,
            'idempotency_key' => 'pay_'.Str::lower(Str::random(28)),
        ]);

        $result = $gateway->createPayment(new PaymentIntent(
            idempotencyKey: (string) $payment->idempotency_key,
            amount: $balance,
            reference: $payment->id,
            description: 'Invoice '.$invoice->number,
            customerEmail: $invoice->bill_to_email,
            customerName: $invoice->bill_to_name,
            returnUrl: $returnUrl,
            metadata: ['invoice' => $invoice->number],
        ));

        $payment->forceFill([
            'reference' => $result->reference ?? $payment->reference,
            'status' => $result->status->value,
            'failure_reason' => $result->failureReason,
            'failed_at' => $result->status === PaymentStatus::Failed ? CarbonImmutable::now() : null,
            'received_at' => $result->status === PaymentStatus::Completed ? CarbonImmutable::now() : null,
        ])->save();

        // What the invoice screen shows as the last capture attempt.
        // Recorded whether or not it worked, which is the whole point: a
        // successful one makes the invoice paid and needs no column, and
        // an operator looking at an unpaid invoice needs to know whether
        // the gateway is broken or the card is.
        $invoice->forceFill([
            'last_capture_at' => CarbonImmutable::now(),
            'last_capture_outcome' => $result->status->value,
        ])->save();

        // Believed because it is the answer to a call this server made, not
        // something a browser told us on the way back.
        if ($result->status === PaymentStatus::Completed) {
            $this->payments->attach($invoice, $payment);
        }

        Audit::action('billing.payment.started')
            ->bySystem('gateway:'.$gatewayKey)
            ->on($payment)
            ->forOrganization($payment->organization_id)
            ->withMetadata([
                'invoice' => $invoice->number,
                'amount' => $balance->toDecimalString(),
                'currency' => $balance->currency->code,
                'status' => $result->status->value,
            ])
            ->write();

        return $result;
    }
}
