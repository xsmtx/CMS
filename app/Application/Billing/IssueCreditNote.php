<?php

declare(strict_types=1);

namespace App\Application\Billing;

use App\Application\Billing\Exceptions\PaymentRefused;
use App\Application\Shared\AllocateNumber;
use App\Domain\Billing\InvoiceStatus;
use App\Domain\Billing\TransactionKind;
use App\Domain\Shared\Money;
use App\Infrastructure\Billing\Models\CreditNote;
use App\Infrastructure\Billing\Models\Invoice;
use App\Support\Audit\Facades\Audit;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Correct an issued invoice.
 *
 * The only way to change what an invoice says after it exists. The invoice
 * itself is untouched — a second numbered document states what was wrong
 * and by how much, which is how accounting works and why an audit can
 * follow it.
 */
final readonly class IssueCreditNote
{
    public function __construct(
        private Ledger $ledger,
        private AllocateNumber $numbers,
        private RecordPayment $payments,
    ) {}

    public function handle(Invoice $invoice, Money $amount, string $reason, ?Model $actor = null): CreditNote
    {
        // A draft is edited, not credited.
        if ($invoice->status === InvoiceStatus::Draft) {
            throw PaymentRefused::alreadyPaid();
        }

        if ($amount->currency->code !== $invoice->currency_code) {
            throw PaymentRefused::currencyMismatch($invoice->currency_code, $amount->currency->code);
        }

        $this->assertWithinInvoice($invoice, $amount);

        $note = DB::transaction(function () use ($invoice, $amount, $reason, $actor): CreditNote {
            $note = CreditNote::query()->create([
                'organization_id' => $invoice->organization_id,
                'invoice_id' => $invoice->id,
                'number' => $this->numbers->handle(
                    $invoice->organization_id,
                    'credit_note',
                    (string) config('platform.billing.numbering.credit_note_prefix', 'CN-'),
                    (int) config('platform.billing.numbering.padding', 6),
                ),
                'currency_code' => $amount->currency->code,
                'amount_minor' => $amount->minorUnits,
                'reason' => $reason,
                'issued_by' => $actor?->getAttribute('email'),
                'issued_on' => CarbonImmutable::now()->toDateString(),
            ]);

            $customer = $invoice->customer;

            if ($customer !== null) {
                $this->ledger->record(
                    customer: $customer,
                    kind: TransactionKind::CreditNote,
                    amount: $amount,
                    invoice: $invoice,
                    description: $note->number.': '.$reason,
                    recordedBy: $actor?->getAttribute('email'),
                );
            }

            return $note;
        });

        $this->payments->settle($invoice, $actor);

        Audit::action('billing.credit_note.issued')
            ->by($actor)
            ->on($note)
            ->forOrganization($note->organization_id)
            ->because($reason)
            ->withMetadata([
                'invoice' => $invoice->number,
                'amount' => $amount->toDecimalString(),
                'currency' => $amount->currency->code,
            ])
            ->write();

        return $note;
    }

    /**
     * Credit notes on one invoice cannot together exceed what it was for.
     */
    private function assertWithinInvoice(Invoice $invoice, Money $amount): void
    {
        $credited = Money::ofMinor(
            (int) $invoice->creditNotes()->sum('amount_minor'),
            $invoice->currency_code,
        );

        $remaining = $invoice->total->minus($credited);

        if (! $amount->isPositive() || $amount->isGreaterThan($remaining)) {
            throw PaymentRefused::exceedsBalance($amount, $remaining);
        }
    }
}
