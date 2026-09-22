<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Application\Billing\AddCredit;
use App\Application\Billing\ApplyCredit;
use App\Application\Billing\IssueCreditNote;
use App\Application\Billing\RecordPayment;
use App\Application\Billing\RecordPaymentRequest;
use App\Application\Billing\RefundPayment;
use App\Domain\Shared\Money;
use App\Http\Controllers\Controller;
use App\Http\Requests\Billing\CreditNoteRequest;
use App\Http\Requests\Billing\CreditRequest;
use App\Http\Requests\Billing\RecordPaymentFormRequest;
use App\Http\Requests\Billing\RefundRequestForm;
use App\Infrastructure\Billing\Models\Invoice;
use App\Infrastructure\Billing\Models\Payment;
use App\Support\Identity\CurrentActor;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;

/**
 * The actions that move money on an invoice.
 *
 * Separate from the invoice controller because each one answers to its own
 * permission: an operator who may edit a draft need not be the one who says
 * a transfer arrived, and the person who may say that need not be the one
 * who can send it back.
 */
final class InvoicePaymentController extends Controller
{
    public function __construct(private readonly CurrentActor $actor) {}

    public function store(RecordPaymentFormRequest $request, Invoice $invoice, RecordPayment $record): RedirectResponse
    {
        $this->authorize('recordPayment', $invoice);

        $record->handle($invoice, new RecordPaymentRequest(
            amount: Money::ofMinor((int) $request->input('amount_minor'), $invoice->currency_code),
            gateway: $request->string('gateway')->toString(),
            reference: $request->input('reference'),
            receivedAt: $request->input('received_on') === null
                ? null
                : CarbonImmutable::parse((string) $request->input('received_on')),
            recordedBy: $this->actor->model()?->getAttribute('email'),
            note: $request->input('note'),
        ), $this->actor->model());

        return back()->with('status', __('billing.payments.recorded'));
    }

    public function refund(RefundRequestForm $request, Invoice $invoice, Payment $payment, RefundPayment $refund): RedirectResponse
    {
        $this->authorize('refund', $invoice);

        abort_unless($payment->invoice_id === $invoice->id, 404);

        $refund->handle(
            $payment,
            Money::ofMinor((int) $request->input('amount_minor'), $payment->currency_code),
            $request->string('reason')->toString(),
            $this->actor->model(),
        );

        return back()->with('status', __('billing.payments.refunded_message'));
    }

    public function applyCredit(CreditRequest $request, Invoice $invoice, ApplyCredit $credit): RedirectResponse
    {
        $this->authorize('credit', $invoice);

        $credit->handle(
            $invoice,
            Money::ofMinor((int) $request->input('amount_minor'), $invoice->currency_code),
            $this->actor->model(),
        );

        return back()->with('status', __('billing.credits.applied', ['number' => $invoice->number]));
    }

    public function addCredit(CreditRequest $request, Invoice $invoice, AddCredit $credit): RedirectResponse
    {
        $this->authorize('credit', $invoice);

        $customer = $invoice->customer;

        abort_if($customer === null, 404);

        $credit->handle(
            $customer,
            Money::ofMinor((int) $request->input('amount_minor'), $invoice->currency_code),
            $request->string('reason')->toString(),
            $this->actor->model(),
        );

        return back()->with('status', __('billing.credits.added'));
    }

    public function creditNote(CreditNoteRequest $request, Invoice $invoice, IssueCreditNote $issue): RedirectResponse
    {
        $this->authorize('credit', $invoice);

        $note = $issue->handle(
            $invoice,
            Money::ofMinor((int) $request->input('amount_minor'), $invoice->currency_code),
            $request->string('reason')->toString(),
            $this->actor->model(),
        );

        return back()->with('status', __('billing.credit_notes.issued', ['number' => $note->number]));
    }
}
