<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Application\Support\OpenTicket;
use App\Application\Support\ReplyToTicket;
use App\Application\Support\SellerDepartments;
use App\Domain\Support\TicketPriority;
use App\Http\Api\ApiResource;
use App\Http\Api\QueryOptions;
use App\Http\Requests\Api\TicketReplyRequest;
use App\Http\Requests\Api\TicketRequest;
use App\Infrastructure\Support\Models\Department;
use App\Infrastructure\Support\Models\Ticket;
use App\Infrastructure\Support\Models\TicketReply;
use App\Support\Errors\ValidationFailedException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Support, from a machine.
 *
 * The write endpoints are what make this worth having: a monitoring system
 * that opens a ticket when it notices a problem, before a customer does.
 * They call `OpenTicket` and `ReplyToTicket` — the same use cases the
 * portal and the admin panel call — so the SLA clock, the notifications and
 * the status transitions all happen exactly as they do for a person.
 *
 * `publicReplies` here too, and for the same reason: an internal note is a
 * conversation between colleagues about the person holding this token.
 */
final class TicketController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $options = new QueryOptions(
            filters: ['status' => 'status', 'priority' => 'priority'],
            sorts: ['created_at', 'last_reply_at'],
            defaultSort: '-last_reply_at',
        );

        $tickets = $options
            ->applyTo($this->customer->owned(Ticket::query()), $request)
            ->paginate($options->perPage($request));

        return new JsonResponse(ApiResource::page(
            $tickets,
            array_values(array_map($this->row(...), $tickets->items())),
        ));
    }

    public function show(string $ticket): JsonResponse
    {
        $record = $this->find($ticket);

        $record->load('publicReplies');

        return new JsonResponse(ApiResource::item([
            ...$this->row($record),
            'replies' => $record->publicReplies
                ->map(static fn (TicketReply $reply): array => [
                    'id' => $reply->id,
                    'author' => $reply->author_name,
                    'from_staff' => $reply->isFromStaff(),
                    'body' => $reply->body,
                    'created_at' => $reply->created_at->toIso8601String(),
                ])
                ->values()
                ->all(),
        ]));
    }

    public function store(
        TicketRequest $request,
        OpenTicket $tickets,
        SellerDepartments $departments,
    ): JsonResponse {
        $department = $departments->find(
            $this->customer->model(),
            $request->string('department_id')->toString(),
        );

        if (! $department instanceof Department) {
            throw new ValidationFailedException(
                (string) __('api.errors.unknown_department'),
                ['department_id' => [(string) __('support.errors.department_required')]],
            );
        }

        $ticket = $tickets->handle(
            $this->customer->model(),
            $this->customer->contact(),
            $department,
            $request->string('subject')->toString(),
            $request->string('body')->toString(),
            TicketPriority::tryFrom((string) $request->input('priority')) ?? TicketPriority::Normal,
        );

        return new JsonResponse(
            ApiResource::item($this->row($ticket)),
            JsonResponse::HTTP_CREATED,
        );
    }

    public function reply(
        TicketReplyRequest $request,
        string $ticket,
        ReplyToTicket $replies,
    ): JsonResponse {
        $record = $this->find($ticket);

        $reply = $replies->fromCustomer(
            $record,
            $this->customer->contact(),
            $request->string('body')->toString(),
        );

        return new JsonResponse(
            ApiResource::item([
                'id' => $reply->id,
                'ticket_id' => $record->id,
                'created_at' => $reply->created_at->toIso8601String(),
            ]),
            JsonResponse::HTTP_CREATED,
        );
    }

    private function find(string $id): Ticket
    {
        /** @var Ticket $ticket */
        $ticket = $this->customer->find(Ticket::query()->whereKey($id));

        return $ticket;
    }

    /**
     * @return array<string, mixed>
     */
    private function row(Ticket $ticket): array
    {
        return [
            'id' => $ticket->id,
            'number' => $ticket->number,
            'subject' => $ticket->subject,
            'status' => $ticket->status->value,
            'is_open' => $ticket->status->isOpen(),
            'priority' => $ticket->priority->value,
            'department_id' => $ticket->department_id,
            'last_reply_at' => $ticket->last_reply_at?->toIso8601String(),
            'created_at' => $ticket->created_at?->toIso8601String(),
        ];
    }
}
