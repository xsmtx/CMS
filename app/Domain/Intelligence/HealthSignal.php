<?php

declare(strict_types=1);

namespace App\Domain\Intelligence;

/**
 * Something true about a customer that somebody should know (§21).
 *
 * **There is no score, and there will not be one.** §21 asks for customer
 * health to be *explainable*, and the only honest way to be explainable is
 * not to compute the thing that would need explaining. A number between 0
 * and 100 is a number somebody acts on and nobody can reproduce, and the
 * first argument about it is the last time anyone opens the screen.
 *
 * So a customer has a list of signals, each with its own arithmetic and its
 * own sentence, and no total. Two customers cannot be ranked against each
 * other by a figure this platform invented — they are ordered by the worst
 * thing that is true of them, which is an ordering the product already has
 * words for.
 *
 * **A signal with nothing to say is absent.** "0 overdue invoices" beside
 * "0 failed services" is a wall of zeroes somebody stops reading, and the
 * one signal that is not zero disappears into it.
 */
enum HealthSignal: string
{
    /** Issued, past its due date, and not paid. */
    case Overdue = 'overdue';

    /** A service that could not be set up and is sitting in `failed`. */
    case ServiceFailed = 'service_failed';

    /** An operation that ended badly, or is waiting for a person. */
    case OperationFailed = 'operation_failed';

    /** A ticket past the SLA its department states. */
    case TicketBreached = 'ticket_breached';

    /** The card the next renewal would be charged to is about to expire. */
    case CardExpiring = 'card_expiring';

    /** An abuse case somebody still has to answer (§13). */
    case AbuseOpen = 'abuse_open';

    /** Something of theirs nothing is invoicing (§21). */
    case NotBilled = 'not_billed';

    public function labelKey(): string
    {
        return 'intelligence.health.signals.'.$this->value;
    }

    public function descriptionKey(): string
    {
        return 'intelligence.health.descriptions.'.$this->value;
    }

    /**
     * How bad this kind of signal is, before its own figure is looked at.
     *
     * A word `status.ts` knows, pinned for every enum by `VocabularyTest`.
     * Two of them are `critical` because they are already costing the
     * customer something — a service that never came up, and an abuse case
     * that ends in a suspension. The rest are worth somebody's morning.
     */
    public function tone(): string
    {
        return match ($this) {
            self::ServiceFailed, self::AbuseOpen => 'critical',
            self::Overdue, self::OperationFailed, self::TicketBreached,
            self::CardExpiring, self::NotBilled => 'warning',
        };
    }

    /**
     * Whether the figure beside it is money rather than a count.
     *
     * Money is a list and never a number (`MoneyByCurrency`), so the two
     * are drawn differently and a screen has to be told which it has.
     */
    public function isMoney(): bool
    {
        return $this === self::Overdue || $this === self::NotBilled;
    }
}
