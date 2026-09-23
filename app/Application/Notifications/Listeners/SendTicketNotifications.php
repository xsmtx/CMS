<?php

declare(strict_types=1);

namespace App\Application\Notifications\Listeners;

use App\Application\Notifications\Notifier;
use App\Application\Notifications\ResolveRecipients;
use App\Domain\Notifications\NotificationEvent;
use App\Domain\Notifications\NotificationRecipient;
use App\Domain\Support\Events\TicketOpened;
use App\Domain\Support\Events\TicketReplied;
use App\Infrastructure\Identity\Models\StaffUser;
use App\Infrastructure\Support\Models\Ticket;
use App\Infrastructure\Support\Models\TicketReply;

/**
 * Who hears about a ticket.
 *
 * Three rules, and they are the difference between a support system people
 * use and one they mute:
 *
 * - **Nobody is told about their own message.** An agent who replies does
 *   not get an email saying an agent replied.
 * - **An internal note notifies nobody outside.** It is a note to
 *   colleagues; the customer must never learn it exists.
 * - **A customer reply goes to the assignee**, and to everyone only when
 *   nobody has claimed it. A team of eight does not need eight emails
 *   about a ticket one of them owns.
 */
final readonly class SendTicketNotifications
{
    public function __construct(
        private Notifier $notifier,
        private ResolveRecipients $recipients,
    ) {}

    public function opened(TicketOpened $event): void
    {
        $ticket = $this->ticket($event->ticketId);

        if (! $ticket instanceof Ticket) {
            return;
        }

        // The customer gets a receipt, so they know it arrived — and so
        // does anybody the desk copied in.
        $customerSide = [
            ...($ticket->contact === null
                ? []
                : [$this->recipients->forContact($ticket->contact, NotificationEvent::TicketOpened)]),
            ...$this->carbonCopies($ticket),
        ];

        if ($customerSide !== []) {
            $this->notifier->send(
                NotificationEvent::TicketOpened,
                $customerSide,
                $this->dataFor($ticket),
                url('/client/support/'.$ticket->id),
                organizationId: $event->organizationId,
            );
        }

        $this->notifier->send(
            NotificationEvent::TicketOpened,
            $this->staffFor($ticket),
            $this->dataFor($ticket),
            url('/admin/support/'.$ticket->id),
            organizationId: $event->organizationId,
        );
    }

    public function replied(TicketReplied $event): void
    {
        $ticket = $this->ticket($event->ticketId);

        if (! $ticket instanceof Ticket) {
            return;
        }

        $reply = TicketReply::query()
            ->withoutGlobalScope('organization')
            ->find($event->replyId);

        // An internal note is a note to colleagues. The customer must never
        // learn it exists, which means never receiving anything about it.
        if ($reply instanceof TicketReply && $reply->is_internal) {
            return;
        }

        if ($event->fromStaff) {
            if ($ticket->contact === null) {
                return;
            }

            $this->notifier->send(
                NotificationEvent::TicketReplied,
                [
                    $this->recipients->forContact($ticket->contact, NotificationEvent::TicketReplied),
                    ...$this->carbonCopies($ticket),
                ],
                $this->dataFor($ticket),
                url('/client/support/'.$ticket->id),
                organizationId: $event->organizationId,
            );

            return;
        }

        $this->notifier->send(
            NotificationEvent::TicketReplied,
            $this->staffFor($ticket),
            $this->dataFor($ticket),
            url('/admin/support/'.$ticket->id),
            organizationId: $event->organizationId,
        );
    }

    /**
     * The addresses the desk copied in.
     *
     * A bare address, with no contact and no portal account behind it —
     * the developer the customer hired for a fortnight, the accounts
     * mailbox that wants the thread. There is no preference to consult
     * because there is nobody here to hold one; the desk put them on the
     * conversation and taking them off it is the desk's job too.
     *
     * @return list<NotificationRecipient>
     */
    private function carbonCopies(Ticket $ticket): array
    {
        $recipients = [];

        foreach ($ticket->cc_recipients ?? [] as $address) {
            if (! is_string($address) || trim($address) === '') {
                continue;
            }

            $recipients[] = new NotificationRecipient(
                name: $address,
                email: $address,
                locale: (string) config('app.locale', 'en'),
            );
        }

        return $recipients;
    }

    /**
     * The assignee if there is one, everybody otherwise.
     *
     * @return list<NotificationRecipient>
     */
    private function staffFor(Ticket $ticket): array
    {
        $assignee = $ticket->assignee;

        if ($assignee instanceof StaffUser) {
            return [$this->recipients->forStaff($assignee)];
        }

        return $this->recipients->staffFor();
    }

    /**
     * @return array<string, string>
     */
    private function dataFor(Ticket $ticket): array
    {
        return [
            'ticket_number' => $ticket->number,
            'subject' => $ticket->subject,
            'department' => $ticket->department === null ? '' : $ticket->department->name,
        ];
    }

    private function ticket(string $id): ?Ticket
    {
        return Ticket::query()
            ->withoutGlobalScope('organization')
            ->with(['contact', 'assignee', 'department'])
            ->find($id);
    }
}
