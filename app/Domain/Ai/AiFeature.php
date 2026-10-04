<?php

declare(strict_types=1);

namespace App\Domain\Ai;

/**
 * What a model is being asked for (ADR 0050).
 *
 * A closed list, and every member is **a draft a person then edits**. There is
 * no member that answers a customer, decides anything, or writes a row — and
 * adding one would be a change to ADR 0050 rather than a new case here.
 *
 * It exists as an enum rather than a free string for three reasons that all
 * matter: the usage row says which feature spent the money, an operator turns
 * features on one at a time, and the prompt builder is a `match` on it with no
 * default, so a new feature cannot be added without saying what it sends.
 */
enum AiFeature: string
{
    /**
     * A reply to a ticket, dropped into the box the operator was going to
     * type in. It is a predefined reply that happens to have been written for
     * this ticket.
     */
    case TicketReply = 'ticket_reply';

    /**
     * What a long thread is about, for whoever picks it up next. Read by
     * staff and never sent anywhere.
     */
    case TicketSummary = 'ticket_summary';

    /**
     * Which department and priority an unrouted ticket looks like. A
     * suggestion beside the field, never the stored value.
     */
    case TicketTriage = 'ticket_triage';

    /**
     * An incident update or a postmortem. §15 already requires a human to
     * publish, so this changes nothing about who is accountable.
     */
    case IncidentUpdate = 'incident_update';

    public function labelKey(): string
    {
        return 'ai.features.'.$this->value.'.label';
    }

    public function descriptionKey(): string
    {
        return 'ai.features.'.$this->value.'.description';
    }

    /**
     * Whether the draft is ever read by somebody outside this installation.
     *
     * Not a permission and not a gate — it is what the settings screen uses to
     * say which features put a seller's words in front of a customer, because
     * an operator deciding whether to turn one on deserves that distinction.
     * Summarising a thread for a colleague and drafting what a customer will
     * read are different risks.
     */
    public function reachesACustomer(): bool
    {
        return match ($this) {
            self::TicketReply, self::IncidentUpdate => true,
            self::TicketSummary, self::TicketTriage => false,
        };
    }
}
