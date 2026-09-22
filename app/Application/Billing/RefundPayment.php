<?php

declare(strict_types=1);

namespace App\Application\Billing;

use App\Application\Billing\Exceptions\PaymentRefused;
use App\Domain\Billing\Contracts\PaymentGateway;
use App\Domain\Billing\PaymentStatus;
use App\Domain\Billing\RefundRequest;
use App\Domain\Billing\TransactionKind;
use App\Domain\Shared\Money;
use App\Infrastructure\Billing\GatewayRegistry;
use App\Infrastructure\Billing\Models\Payment;
use App\Infrastructure\Billing\Models\Transaction;
use App\Support\Audit\Facades\Audit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Send money back.
 *
 * The gateway call happens outside the transaction, because a database
 * transaction held open across a remote call is a lock waiting on a network
 * timeout. The ledger is written only after the provider accepts — a refund
 * row for money that never moved is worse than no row at all.
 */
final readonly class RefundPayment
{
    public function __construct(
        private Ledger $ledger,
        private RecordPayment $payments,
        private GatewayRegistry $gateways,
    ) {}

    public function handle(Payment $payment, Money $amount, ?string $reason = null, ?Model $actor = null): Transaction
    {
        $this->assertRefundable($payment, $amount);

        $reference = $this->askGateway($payment, $amount, $reason);

        $customer = $payment->customer;

        if ($customer === null) {
            // A payment always belongs to a customer; one that does not is
            // a broken row, and guessing where the money came from is not
            // this use case's job.
            throw PaymentRefused::exceedsPayment($amount, $payment->refundable());
        }

        $transaction = DB::transaction(function () use ($payment, $customer, $amount, $reason, $actor): Transaction {
            $refunded = $payment->refunded->plus($amount);

            $payment->forceFill([
                'refunded_minor' => $refunded->minorUnits,
                'status' => $refunded->equals($payment->amount)
                    ? PaymentStatus::Refunded->value
                    : PaymentStatus::PartiallyRefunded->value,
            ])->save();

            return $this->ledger->record(
                customer: $customer,
                kind: TransactionKind::Refund,
                amount: $amount,
                invoice: $payment->invoice,
                payment: $payment,
                description: $reason,
                recordedBy: $actor?->getAttribute('email'),
            );
        });

        $invoice = $payment->invoice;

        if ($invoice !== null) {
            $this->payments->settle($invoice, $actor);
        }

        // Money left the business. Who authorised it is the question asked
        // afterwards, so it is recorded with the reason in their words.
        Audit::action('billing.payment.refunded')
            ->by($actor)
            ->on($payment)
            ->forOrganization($payment->organization_id)
            ->because($reason)
            ->withMetadata([
                'amount' => $amount->toDecimalString(),
                'currency' => $amount->currency->code,
                'gateway' => $payment->gateway,
                'gateway_reference' => $reference,
            ])
            ->write();

        return $transaction;
    }

    /**
     * A gateway that cannot refund by itself still refunds: an operator
     * sends the money and records it, which is what the manual gateway is.
     */
    private function askGateway(Payment $payment, Money $amount, ?string $reason): ?string
    {
        $gateway = $this->gateways->find($payment->gateway);

        if (! $gateway instanceof PaymentGateway
            || ! $gateway->capabilities()->refunds
            || $payment->reference === null) {
            return null;
        }

        $result = $gateway->refund(new RefundRequest(
            idempotencyKey: 'rf_'.Str::lower(Str::random(28)),
            paymentReference: $payment->reference,
            amount: $amount,
            reason: $reason,
        ));

        if (! $result->accepted) {
            throw PaymentRefused::exceedsPayment($amount, $payment->refundable());
        }

        return $result->reference;
    }

    private function assertRefundable(Payment $payment, Money $amount): void
    {
        if ($amount->currency->code !== $payment->currency_code) {
            throw PaymentRefused::currencyMismatch($payment->currency_code, $amount->currency->code);
        }

        $refundable = $payment->refundable();

        if (! $amount->isPositive() || $amount->isGreaterThan($refundable)) {
            throw PaymentRefused::exceedsPayment($amount, $refundable);
        }
    }
}
