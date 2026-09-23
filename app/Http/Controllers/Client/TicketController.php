<?php

declare(strict_types=1);

namespace App\Http\Controllers\Client;

use App\Application\Support\OpenTicket;
use App\Application\Support\ReplyToTicket;
use App\Application\Support\SellerDepartments;
use App\Application\Support\StoreAttachment;
use App\Domain\Support\TicketPriority;
use App\Http\Controllers\Controller;
use App\Http\Requests\Support\OpenTicketRequest;
use App\Http\Requests\Support\TicketReplyRequest;
use App\Infrastructure\Provisioning\Models\Service;
use App\Infrastructure\Support\Models\Department;
use App\Infrastructure\Support\Models\Ticket;
use App\Infrastructure\Support\Models\TicketAttachment;
use App\Infrastructure\Support\Models\TicketReply;
use App\Support\Errors\ForbiddenException;
use App\Support\Identity\CurrentActor;
use App\Support\Identity\CurrentCustomer;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The customer's side of a conversation.
 *
 * `publicReplies` rather than `replies`, everywhere and without exception.
 * An internal note is a message between colleagues about the person
 * reading this screen, and one forgotten `where` puts it in front of them.
 */
final class TicketController extends Controller
{
    public function __construct(
        private readonly CurrentCustomer $customer,
        private readonly CurrentActor $actor,
        private readonly SellerDepartments $departments,
    ) {}

    public function index(): Response
    {
        $this->authorizeTickets('portal.tickets.view');

        $tickets = $this->customer
            ->owned(Ticket::query())
            ->with('department')
            ->latest('last_reply_at')
            ->get();

        return Inertia::render('Client/Support/Index', [
            'tickets' => $tickets->map(fn (Ticket $ticket): array => $this->row($ticket))->values()->all(),
            'can' => ['create' => $this->actor->can('portal.tickets.create')],
        ]);
    }

    public function create(): Response
    {
        $this->authorizeTickets('portal.tickets.create');

        return Inertia::render('Client/Support/Create', [
            'departments' => $this->departments->forCustomer($this->customer->model())
                ->map(static fn (Department $department): array => [
                    'value' => $department->id,
                    'label' => $department->name,
                    'description' => $department->description,
                ])
                ->values()
                ->all(),
            'services' => $this->customer
                ->owned(Service::query())
                ->whereNot('status', 'terminated')
                ->get()
                ->map(static fn (Service $service): array => [
                    'value' => $service->id,
                    'label' => $service->name.($service->domain === null ? '' : ' — '.$service->domain),
                ])
                ->values()
                ->all(),
        ]);
    }

    public function store(
        OpenTicketRequest $request,
        OpenTicket $tickets,
        StoreAttachment $attachments,
    ): RedirectResponse {
        $this->authorizeTickets('portal.tickets.create');

        $department = $this->departments->find(
            $this->customer->model(),
            $request->string('department_id')->toString(),
        );

        if (! $department instanceof Department) {
            // Not theirs to pick, so as far as this customer is concerned
            // it does not exist.
            throw new NotFoundHttpException;
        }

        $serviceId = $request->input('service_id');

        // Scoped to this customer: a ticket must not be able to name
        // somebody else's service just by guessing an id.
        $service = $serviceId === null
            ? null
            : $this->customer->owned(Service::query())->whereKey($serviceId)->first();

        $ticket = $tickets->handle(
            $this->customer->model(),
            $this->customer->contact(),
            $department,
            $request->string('subject')->toString(),
            $request->string('body')->toString(),
            TicketPriority::tryFrom((string) $request->input('priority')) ?? TicketPriority::Normal,
            ['service_id' => $service?->id],
        );

        foreach ((array) $request->file('attachments', []) as $file) {
            $attachments->handle($ticket, $file, $ticket->replies()->first());
        }

        return to_route('client.ticket', $ticket->id)
            ->with('status', __('support.portal.opened', ['number' => $ticket->number]));
    }

    public function show(string $ticket): Response
    {
        $this->authorizeTickets('portal.tickets.view');

        $record = $this->find($ticket);

        // Public replies only. The one place this could go wrong is a
        // caller reaching for `replies`, which is why there are two
        // relations rather than one plus a filter.
        $record->load(['department', 'publicReplies.attachments', 'service']);

        return Inertia::render('Client/Support/Show', [
            'ticket' => [
                ...$this->row($record),
                'service' => $record->service?->name,
                'replies' => $record->publicReplies
                    ->map(fn (TicketReply $reply): array => [
                        'id' => $reply->id,
                        'author' => $reply->author_name,
                        'fromStaff' => $reply->isFromStaff(),
                        'body' => $reply->body,
                        'createdAt' => $reply->created_at->toIso8601String(),
                        'attachments' => $reply->attachments
                            ->map(fn (TicketAttachment $file): array => [
                                'id' => $file->id,
                                'name' => $file->original_name,
                                'size' => $file->humanSize(),
                            ])
                            ->values()
                            ->all(),
                    ])
                    ->values()
                    ->all(),
            ],
        ]);
    }

    public function reply(
        TicketReplyRequest $request,
        string $ticket,
        ReplyToTicket $replies,
        StoreAttachment $attachments,
    ): RedirectResponse {
        $this->authorizeTickets('portal.tickets.create');

        $record = $this->find($ticket);

        $reply = $replies->fromCustomer(
            $record,
            $this->customer->contact(),
            $request->string('body')->toString(),
        );

        foreach ((array) $request->file('attachments', []) as $file) {
            $attachments->handle($record, $file, $reply);
        }

        return back()->with('status', __('support.tickets.replied'));
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
            'statusLabel' => (string) __($ticket->status->labelKey()),
            'isOpen' => $ticket->status->isOpen(),
            'department' => $ticket->department?->name,
            'openedAt' => $ticket->created_at?->toIso8601String(),
            'lastReplyAt' => $ticket->last_reply_at?->toIso8601String(),
            'awaitingUs' => $ticket->status->needsUs(),
        ];
    }

    private function authorizeTickets(string $permission): void
    {
        if (! $this->actor->can($permission)) {
            throw new ForbiddenException(__('support.tickets.not_permitted'));
        }
    }
}
