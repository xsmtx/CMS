<?php

declare(strict_types=1);

namespace App\Application\Billing;

use App\Domain\Billing\Contracts\PaymentGateway;
use App\Domain\Billing\GatewayEvent;
use App\Domain\Billing\GatewayEventType;
use App\Domain\Billing\PaymentStatus;
use App\Domain\Billing\WebhookRequest;
use App\Infrastructure\Billing\GatewayRegistry;
use App\Infrastructure\Billing\Models\GatewayEventRecord;
use App\Infrastructure\Billing\Models\Payment;
use App\Support\Audit\Facades\Audit;
use App\Support\Logging\SecretRedactor;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * What to do when a gateway calls back.
 *
 * Four things happen in this order, and the order is the whole point:
 *
 * 1. The signature is verified. An unverified payload is not read.
 * 2. The event is recorded, and the unique index on (gateway, event id) is
 *    what makes a delivery repeated five times produce one payment. A
 *    check-then-write would race; the index cannot.
 * 3. The payment it refers to is found and moved.
 * 4. The invoice is settled through the same path an operator uses.
 *
 * Nothing here trusts a redirect, a query parameter or an unsigned body.
 */
final readonly class HandleGatewayEvent
{
    public function __construct(
        private GatewayRegistry $gateways,
        private RecordPayment $payments,
        private SecretRedactor $redactor,
    ) {}

    /**
     * @return array{handled: bool, reason: string}
     */
    public function handle(string $gatewayKey, WebhookRequest $request): array
    {
        $gateway = $this->gateways->find($gatewayKey);

        if (! $gateway instanceof PaymentGateway) {
            return ['handled' => false, 'reason' => 'unknown_gateway'];
        }

        $event = $gateway->verifyWebhook($request);

        if (! $event instanceof GatewayEvent) {
            // A bad signature is recorded without its payload: whatever
            // that body is, it is not something this platform vouched for.
            $this->recordRejection($gatewayKey);

            return ['handled' => false, 'reason' => 'invalid_signature'];
        }

        $record = $this->remember($gatewayKey, $event);

        if ($record === null) {
            // Seen before. The first delivery did the work.
            return ['handled' => true, 'reason' => 'duplicate'];
        }

        if (! $event->isActionable()) {
            $this->close($record, 'ignored');

            return ['handled' => true, 'reason' => 'ignored'];
        }

        $payment = $this->findPayment($gatewayKey, $event);

        if (! $payment instanceof Payment) {
            $this->close($record, 'unmatched');

            return ['handled' => true, 'reason' => 'unmatched'];
        }

        $record->forceFill(['organization_id' => $payment->organization_id])->save();

        $this->apply($event, $payment);
        $this->close($record, 'processed');

        return ['handled' => true, 'reason' => $event->type->value];
    }

    /**
     * Claim the event, or discover somebody already did.
     *
     * Null means a duplicate. The unique constraint is the arbiter rather
     * than a prior select, because two deliveries can arrive at once.
     */
    private function remember(string $gatewayKey, GatewayEvent $event): ?GatewayEventRecord
    {
        try {
            /** @var array<string, mixed> $payload */
            $payload = $this->redactor->redact($event->payload);

            return GatewayEventRecord::query()->create([
                // Attributed to an organization once the payment is found;
                // an event for an unknown payment belongs to nobody yet.
                'organization_id' => null,
                'gateway' => $gatewayKey,
                'event_id' => $event->id,
                'type' => $event->type->value,
                'payment_reference' => $event->paymentReference,
                'outcome' => 'received',
                'payload' => $payload,
                'received_at' => CarbonImmutable::now(),
            ]);
        } catch (UniqueConstraintViolationException) {
            return null;
        }
    }

    private function findPayment(string $gatewayKey, GatewayEvent $event): ?Payment
    {
        if ($event->paymentReference === null) {
            return null;
        }

        return Payment::query()
            ->withoutGlobalScope('organization')
            ->where('gateway', $gatewayKey)
            ->where('reference', $event->paymentReference)
            ->first();
    }

    private function apply(GatewayEvent $event, Payment $payment): void
    {
        match ($event->type) {
            GatewayEventType::PaymentCompleted => $this->complete($payment),
            GatewayEventType::PaymentFailed => $this->fail($payment, $event->failureReason),
            GatewayEventType::PaymentRefunded => $this->refunded($payment),
            GatewayEventType::Ignored => null,
        };
    }

    /**
     * A payment that is already complete stays complete. A webhook that
     * arrives twice under two event ids must not settle an invoice twice.
     */
    private function complete(Payment $payment): void
    {
        if ($payment->status === PaymentStatus::Completed) {
            return;
        }

        DB::transaction(function () use ($payment): void {
            $payment->forceFill([
                'status' => PaymentStatus::Completed->value,
                'received_at' => CarbonImmutable::now(),
                'failure_reason' => null,
                'failed_at' => null,
            ])->save();
        });

        $invoice = $payment->invoice;

        if ($invoice !== null) {
            // The same path an operator's manual entry takes, so a card and
            // a bank transfer produce the same ledger and the same
            // transitions.
            $this->payments->attach($invoice, $payment);
        }

        Audit::action('billing.payment.completed')
            ->bySystem('gateway:'.$payment->gateway)
            ->on($payment)
            ->forOrganization($payment->organization_id)
            ->withMetadata([
                'amount' => $payment->amount->toDecimalString(),
                'currency' => $payment->currency_code,
                'reference' => $payment->reference,
            ])
            ->write();
    }

    private function fail(Payment $payment, ?string $reason): void
    {
        if ($payment->status->isSuccessful()) {
            // Money already arrived. A later failure event is about a
            // different attempt, or is noise.
            return;
        }

        $payment->forceFill([
            'status' => PaymentStatus::Failed->value,
            'failure_reason' => $reason,
            'failed_at' => CarbonImmutable::now(),
        ])->save();

        Audit::action('billing.payment.failed')
            ->bySystem('gateway:'.$payment->gateway)
            ->on($payment)
            ->forOrganization($payment->organization_id)
            ->because($reason)
            ->write();
    }

    /**
     * A refund made at the gateway rather than here.
     *
     * Recorded, not acted on: reconciling it against the ledger is an
     * operator's decision, and inventing a refund row from a payload we did
     * not initiate would put money movements in the books that nobody
     * authorised in this system.
     */
    private function refunded(Payment $payment): void
    {
        Audit::action('billing.payment.refunded_externally')
            ->bySystem('gateway:'.$payment->gateway)
            ->on($payment)
            ->forOrganization($payment->organization_id)
            ->withMetadata(['reference' => $payment->reference])
            ->write();
    }

    private function close(GatewayEventRecord $record, string $outcome): void
    {
        $record->forceFill([
            'outcome' => $outcome,
            'processed_at' => CarbonImmutable::now(),
        ])->save();
    }

    private function recordRejection(string $gatewayKey): void
    {
        try {
            GatewayEventRecord::query()->create([
                'organization_id' => null,
                'gateway' => $gatewayKey,
                // No event id to trust, so the row is keyed by when it
                // arrived. Unverified bodies are never stored.
                'event_id' => 'rejected:'.bin2hex(random_bytes(12)),
                'type' => 'rejected',
                'outcome' => 'rejected',
                'error' => 'signature_verification_failed',
                'payload' => null,
                'received_at' => CarbonImmutable::now(),
                'processed_at' => CarbonImmutable::now(),
            ]);
        } catch (UniqueConstraintViolationException) {
            // Nothing to do: the rejection is already on record.
        }
    }
}
