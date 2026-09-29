<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Staff;

use App\Application\Support\ReplyToTicket;
use App\Domain\Support\TicketStatus;
use App\Http\Controllers\Controller;
use App\Infrastructure\Identity\Models\StaffUser;
use App\Infrastructure\Support\Models\Ticket;
use App\Infrastructure\Support\Models\TicketReply;
use App\Support\Errors\ForbiddenException;
use App\Support\Identity\CurrentActor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Tickets, and the one thing §26 asks a phone to do to one: answer.
 *
 * Read and reply. No closing, no reassigning, no deleting — an agent
 * answering from a phone is answering, and the rest is desk work that gains
 * nothing from being possible in a queue at a bus stop.
 *
 * `ReplyToTicket::fromStaff()` decides which status a reply implies and then
 * asks `TransitionTicket` for it (ADR 0030). This controller does not touch
 * the status at all, because two places that can move a ticket's clock is one
 * too many.
 */
final class TicketController extends Controller
{
    public function __construct(
        private readonly ReplyToTicket $replies,
        private readonly CurrentActor $actor,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $open = $request->boolean('open', true);

        $tickets = Ticket::query()
            ->with(['department', 'customer'])
            ->when($open, fn ($query) => $query->where('status', '!=', TicketStatus::Closed->value))
            // Whose turn it is, first: that is the one question an agent
            // opens this list to answer.
            ->orderByRaw('status in (?, ?) desc', [
                TicketStatus::Open->value,
                TicketStatus::CustomerReply->value,
            ])
            ->latest('last_reply_at')
            ->limit(100)
            ->get();

        return response()->json([
            'data' => $tickets->map(fn (Ticket $ticket): array => [
                'id' => $ticket->id,
                'number' => $ticket->number,
                'subject' => $ticket->subject,
                'status' => $ticket->status->value,
                'statusLabel' => (string) __($ticket->status->labelKey()),
                'priority' => $ticket->priority->value,
                'priorityLabel' => (string) __($ticket->priority->labelKey()),
                'department' => $ticket->department?->name,
                'customer' => $ticket->customer?->displayName(),
                'awaitingUs' => in_array($ticket->status, [
                    TicketStatus::Open,
                    TicketStatus::CustomerReply,
                ], strict: true),
                // The SLA clock a department stated, which is what makes a
                // queue a queue rather than a list.
                'firstResponseDueAt' => $ticket->first_response_due_at?->toIso8601String(),
                'resolutionDueAt' => $ticket->resolution_due_at?->toIso8601String(),
                'lastReplyAt' => $ticket->last_reply_at?->toIso8601String(),
            ])->values(),
        ]);
    }

    public function show(Ticket $ticket): JsonResponse
    {
        $ticket->load(['replies', 'department', 'customer', 'service']);

        return response()->json([
            'data' => [
                'id' => $ticket->id,
                'number' => $ticket->number,
                'subject' => $ticket->subject,
                'status' => $ticket->status->value,
                'statusLabel' => (string) __($ticket->status->labelKey()),
                // Which of their three hosting accounts this is about, which
                // is the fact the portal was sent and never drew for a phase.
                'service' => $ticket->service?->name,
                'customer' => $ticket->customer?->displayName(),
                'replies' => $ticket->replies->map(fn (TicketReply $reply): array => [
                    'id' => $reply->id,
                    'author' => $reply->author_name,
                    'authorType' => $reply->author_type,
                    'body' => $reply->body,
                    // An internal note is on this list and marked, rather
                    // than filtered out: an agent needs to see what a
                    // colleague wrote before answering.
                    'internal' => $reply->is_internal,
                    'at' => $reply->created_at?->toIso8601String(),
                ])->values(),
            ],
        ]);
    }

    public function reply(Request $request, Ticket $ticket): JsonResponse
    {
        $validated = $request->validate([
            'body' => ['required', 'string', 'min:2', 'max:20000'],
            'internal' => ['nullable', 'boolean'],
        ]);

        $staff = $this->actor->model();

        if (! $staff instanceof StaffUser) {
            throw new ForbiddenException((string) __('api.errors.forbidden'));
        }

        $reply = $this->replies->fromStaff(
            ticket: $ticket,
            author: $staff,
            body: $validated['body'],
            internal: (bool) ($validated['internal'] ?? false),
        );

        return response()->json([
            'data' => [
                'id' => $reply->id,
                // The status the reply implied, decided by one place.
                'status' => $ticket->refresh()->status->value,
            ],
        ], 201);
    }
}
