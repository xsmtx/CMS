<?php

declare(strict_types=1);

namespace App\Application\Support;

use App\Domain\Support\Events\TicketReplied;
use App\Domain\Support\TicketStatus;
use App\Infrastructure\Identity\Models\Contact;
use App\Infrastructure\Identity\Models\StaffUser;
use App\Infrastructure\Support\Models\Ticket;
use App\Infrastructure\Support\Models\TicketReply;
use App\Support\Audit\Facades\Audit;
use App\Support\Correlation\CorrelationContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Adds a message, and moves the clock and the queue with it.
 *
 * The one rule worth stating plainly: **an internal note is not an answer.**
 * It does not stamp `first_responded_at`, it does not move the ticket out
 * of the customer's queue, and it never reaches them. An agent writing
 * "chasing the datacentre, will update" has not answered anybody.
 *
 * `first_responded_at` is stamped only once, by the first public staff
 * reply, because a first-response SLA measures the first response.
 */
final readonly class ReplyToTicket
{
    public function __construct(
        private TransitionTicket $transitions,
        private CorrelationContext $correlation,
    ) {}

    public function fromStaff(
        Ticket $ticket,
        StaffUser $author,
        string $body,
        bool $internal = false,
    ): TicketReply {
        return $this->write(
            $ticket,
            TicketReply::AUTHOR_STAFF,
            $author->id,
            $author->displayName(),
            $body,
            $internal,
            $author,
        );
    }

    public function fromCustomer(Ticket $ticket, Contact $author, string $body): TicketReply
    {
        return $this->write(
            $ticket,
            TicketReply::AUTHOR_CUSTOMER,
            $author->id,
            $author->displayName(),
            $body,
            false,
            $author,
        );
    }

    private function write(
        Ticket $ticket,
        string $authorType,
        ?string $authorId,
        string $authorName,
        string $body,
        bool $internal,
        ?Model $actor,
    ): TicketReply {
        $now = CarbonImmutable::now();
        $fromStaff = $authorType === TicketReply::AUTHOR_STAFF;
        $answers = $fromStaff && ! $internal;

        $reply = DB::transaction(function () use (
            $ticket, $authorType, $authorId, $authorName, $body, $internal, $now, $fromStaff, $answers
        ): TicketReply {
            $reply = $ticket->replies()->create([
                'organization_id' => $ticket->organization_id,
                'author_type' => $authorType,
                'author_id' => $authorId,
                'author_name' => $authorName,
                'body' => $body,
                'is_internal' => $internal,
                'created_at' => $now,
            ]);

            $attributes = [];

            if ($answers) {
                // Stamped once. A first-response SLA measures the first
                // response, not the most recent one.
                $attributes['first_responded_at'] = $ticket->first_responded_at ?? $now;
                $attributes['last_reply_at'] = $now;
                $attributes['last_reply_by'] = TicketReply::AUTHOR_STAFF;
            }

            if (! $fromStaff) {
                $attributes['last_reply_at'] = $now;
                $attributes['last_reply_by'] = TicketReply::AUTHOR_CUSTOMER;
            }

            // An internal note changes none of these: it is not an answer
            // and it does not move whose turn it is.
            if ($attributes !== []) {
                $ticket->forceFill($attributes)->save();
            }

            return $reply;
        });

        // The status goes through the one place that owns it, so a
        // reopened ticket loses its resolution date and the change is
        // audited — neither of which happens when a caller assigns the
        // column itself.
        $target = match (true) {
            $answers => TicketStatus::Answered,
            ! $fromStaff => TicketStatus::CustomerReply,
            default => null,
        };

        if ($target !== null && $ticket->status->canTransitionTo($target)) {
            $this->transitions->handle($ticket, $target, $actor);
        }

        Audit::action($internal ? 'support.ticket.note' : 'support.ticket.replied')
            ->by($actor)
            ->on($ticket)
            ->forOrganization($ticket->organization_id)
            ->write();

        // An internal note produces no event: nothing outside the office
        // should learn it happened.
        if (! $internal) {
            event(new TicketReplied(
                $ticket->id,
                $reply->id,
                $ticket->organization_id,
                $fromStaff,
                $this->correlation->id(),
            ));
        }

        return $reply;
    }
}
