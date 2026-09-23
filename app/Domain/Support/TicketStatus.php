<?php

declare(strict_types=1);

namespace App\Domain\Support;

/**
 * Whose turn it is.
 *
 * That is the whole design. A ticket queue is not a list of problems, it is
 * a list of things waiting for somebody, and every state here answers
 * "waiting for whom".
 *
 * `answered` and `customer_reply` are separate rather than one "in
 * progress", because an agent looking at the queue needs to see the
 * difference between a conversation they have replied to and one that has
 * come back to them.
 */
enum TicketStatus: string
{
    /** Waiting for us. */
    case Open = 'open';

    /** We replied. Waiting for the customer. */
    case Answered = 'answered';

    /** They came back. Ours again. */
    case CustomerReply = 'customer_reply';

    /** Waiting for something outside the conversation. */
    case OnHold = 'on_hold';

    case Closed = 'closed';

    public function labelKey(): string
    {
        return 'support.statuses.'.$this->value;
    }

    /**
     * Whether the ticket is in somebody's queue here.
     */
    public function needsUs(): bool
    {
        return $this === self::Open || $this === self::CustomerReply;
    }

    public function isOpen(): bool
    {
        return $this !== self::Closed;
    }

    /**
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Open => [self::Answered, self::OnHold, self::Closed],
            self::Answered => [self::CustomerReply, self::OnHold, self::Closed, self::Open],
            self::CustomerReply => [self::Answered, self::OnHold, self::Closed, self::Open],
            self::OnHold => [self::Open, self::Answered, self::CustomerReply, self::Closed],
            // A customer replying to a closed ticket reopens it rather than
            // forcing them to start again and re-explain.
            self::Closed => [self::CustomerReply, self::Open],
        };
    }

    public function canTransitionTo(self $next): bool
    {
        return in_array($next, $this->allowedTransitions(), strict: true);
    }
}
