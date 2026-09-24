<?php

declare(strict_types=1);

namespace App\Domain\Automation;

/**
 * What a step in the dunning sequence does.
 *
 * `LateFee` was deliberately absent until somebody decided the question it
 * begs — whether a fee is a new invoice or a line on the next one — because
 * money on a frozen document (ADR 0023) is not something to invent in a phase
 * about scheduling. [ADR 0046](../../../docs/adr/0046-a-late-fee-is-a-new-invoice.md)
 * decides it: **a new invoice**, since the invoice the fee is about is issued and
 * an issued invoice cannot gain a line, and a fee that waited for the next
 * renewal would arrive after the debt it was meant to discourage.
 *
 * It is a step rather than a setting because the timing already is one. A step
 * carries `offset_days`, so "charge a fee fourteen days after the due date"
 * needs no second concept of a grace period, and `invoice_dunning_steps` already
 * remembers that a step ran against an invoice — which is exactly what stops a
 * nightly sweep charging the same fee thirty times.
 */
enum DunningAction: string
{
    case Notify = 'notify';
    case LateFee = 'late_fee';
    case Suspend = 'suspend';
    case Terminate = 'terminate';

    public function labelKey(): string
    {
        return 'automation.dunning.actions.'.$this->value;
    }

    public function needsEvent(): bool
    {
        return $this === self::Notify;
    }

    /**
     * Whether this step takes something away from the customer.
     *
     * A fee does not: it is a charge, and a customer who asked never to be
     * suspended has not asked never to be billed. They are separate columns on
     * the customer record for that reason, and reading the suspension
     * preference for a fee would quietly let anybody opt out of interest.
     */
    public function withholdsService(): bool
    {
        return $this === self::Suspend || $this === self::Terminate;
    }
}
