<?php

declare(strict_types=1);

namespace App\Domain\Billing;

/**
 * What an operator can do to several invoices at once.
 *
 * Deliberately a short list, and deliberately an enum rather than a string
 * the request hands through: a bulk action reaches records the operator has
 * not opened, so the set of things it can be has to be closed.
 *
 * Nothing here destroys anything. An issued invoice is frozen (ADR 0023), so
 * there is no bulk edit and no bulk delete to have — cancelling is a status,
 * and correcting is a credit note somebody writes one at a time on purpose.
 */
enum InvoiceBulkAction: string
{
    /** Draft → issued. Takes a number, freezes the document. */
    case Issue = 'issue';

    /** Anything still open → cancelled. Needs a reason. */
    case Cancel = 'cancel';

    public function labelKey(): string
    {
        return 'billing.invoices.bulk.'.$this->value;
    }

    /**
     * Whether it needs the operator to say why.
     *
     * Issuing does not: the document itself is the record of it. Cancelling
     * does, because six weeks later somebody will ask what happened to an
     * invoice that exists and is owed by nobody.
     */
    public function needsReason(): bool
    {
        return $this === self::Cancel;
    }

    /**
     * Whether this action can do anything to an invoice in this state.
     *
     * A row it cannot touch is **skipped**, not refused: an operator who
     * selected twenty invoices and caught one already-cancelled one among
     * them meant the other nineteen.
     */
    public function appliesTo(InvoiceStatus $status): bool
    {
        return match ($this) {
            self::Issue => $status === InvoiceStatus::Draft,
            self::Cancel => $status->canTransitionTo(InvoiceStatus::Cancelled),
        };
    }
}
