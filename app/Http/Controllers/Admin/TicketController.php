<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Application\Support\OpenTicket;
use App\Application\Support\ReplyToTicket;
use App\Application\Support\StoreAttachment;
use App\Application\Support\TransitionTicket;
use App\Domain\Support\TicketPriority;
use App\Domain\Support\TicketStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Support\TicketReplyRequest;
use App\Infrastructure\Crm\Models\Customer;
use App\Infrastructure\Identity\Models\StaffUser;
use App\Infrastructure\Support\Models\CannedResponse;
use App\Infrastructure\Support\Models\Department;
use App\Infrastructure\Support\Models\Ticket;
use App\Infrastructure\Support\Models\TicketAttachment;
use App\Infrastructure\Support\Models\TicketReply;
use App\Support\Identity\CurrentActor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The queue.
 *
 * Sorted by what is late, because that is what a queue is for. A ticket
 * whose department has no SLA sorts last rather than first: null is "not
 * measured", not "infinitely overdue".
 */
final class TicketController extends Controller
{
    public function __construct(private readonly CurrentActor $actor) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Ticket::class);

        $status = $request->string('status')->toString();
        $mine = $request->boolean('mine');
        $breaching = $request->boolean('breaching');

        $tickets = Ticket::query()
            ->with([...Customer::displayNameWith('customer'), 'department', 'assignee'])
            ->when(
                TicketStatus::tryFrom($status) instanceof TicketStatus,
                fn ($query) => $query->where('status', $status),
                fn ($query) => $query->awaitingUs(),
            )
            ->when($mine, fn ($query) => $query->where('assigned_to', $this->actor->model()?->getKey()))
            ->when($breaching, fn ($query) => $query->breachingSla())
            // Null due dates last: not measured is not overdue.
            ->orderByRaw('first_response_due_at IS NULL, first_response_due_at ASC')
            ->paginate(25)
            ->withQueryString();

        return Inertia::render('Admin/Support/Index', [
            'tickets' => [
                'data' => array_map($this->row(...), $tickets->items()),
                'currentPage' => $tickets->currentPage(),
                'lastPage' => $tickets->lastPage(),
                'total' => $tickets->total(),
            ],
            'filters' => [
                'status' => $status === '' ? null : $status,
                'mine' => $mine,
                'breaching' => $breaching,
            ],
            'statuses' => $this->statuses(),
            'counts' => [
                'awaiting' => Ticket::query()->awaitingUs()->count(),
                'breaching' => Ticket::query()->breachingSla()->count(),
                'mine' => Ticket::query()
                    ->awaitingUs()
                    ->where('assigned_to', $this->actor->model()?->getKey())
                    ->count(),
            ],
        ]);
    }

    /**
     * Open a ticket on a customer's behalf.
     *
     * The call that starts "I rang about this last week" ends with an
     * operator typing it into the queue, and until now there was nowhere
     * to type it: the client area could open a ticket and the desk could
     * not. It goes through the same `OpenTicket` the portal uses, so the
     * SLA clock, the notification and the department's rules are identical
     * — a second path that opened tickets differently would drift, and the
     * drift would be invisible until somebody measured response times.
     */
    public function create(): Response
    {
        $this->authorize('create', Ticket::class);

        return Inertia::render('Admin/Support/Create', [
            'departments' => array_values(Department::query()
                ->orderBy('name')
                ->get()
                ->map(static fn (Department $department): array => [
                    'value' => $department->id,
                    'label' => $department->name,
                ])
                ->all()),
            'priorities' => array_values(array_map(
                static fn (TicketPriority $priority): array => [
                    'value' => $priority->value,
                    'label' => (string) __($priority->labelKey()),
                ],
                TicketPriority::cases(),
            )),
        ]);
    }

    public function store(Request $request, OpenTicket $tickets): RedirectResponse
    {
        $this->authorize('create', Ticket::class);

        $data = $request->validate([
            'customer_id' => ['required', 'ulid', 'exists:customers,id'],
            'department_id' => ['nullable', 'ulid', 'exists:departments,id'],
            'subject' => ['required', 'string', 'max:191'],
            'body' => ['required', 'string', 'max:20000'],
            'priority' => ['nullable', 'string'],
        ]);

        $customer = Customer::query()->whereKey($data['customer_id'])->firstOrFail();

        $ticket = $tickets->handle(
            customer: $customer,
            // Opened by the desk, so it belongs to the account rather than
            // to one person on it. A reply reaches whoever the customer's
            // notification settings say it should.
            contact: null,
            department: $data['department_id'] === null
                ? null
                : Department::query()->whereKey($data['department_id'])->first(),
            subject: (string) $data['subject'],
            body: (string) $data['body'],
            priority: TicketPriority::tryFrom((string) ($data['priority'] ?? '')) ?? TicketPriority::Normal,
        );

        return to_route('admin.support.show', $ticket)->with('status', __('support.tickets_opened'));
    }

    public function show(Ticket $ticket): Response
    {
        $this->authorize('view', $ticket);

        $ticket->load([
            ...Customer::displayNameWith('customer'), 'contact', 'department', 'assignee',
            'replies.attachments', 'service', 'domain', 'invoice',
        ]);

        return Inertia::render('Admin/Support/Show', [
            'ticket' => [
                ...$this->row($ticket),
                'departmentId' => $ticket->department_id,
                'assignedTo' => $ticket->assigned_to,
                'service' => $ticket->service?->name,
                'serviceId' => $ticket->service_id,
                'domain' => $ticket->domain?->name,
                'domainId' => $ticket->domain_id,
                'invoice' => $ticket->invoice?->number,
                'invoiceId' => $ticket->invoice_id,
                'firstRespondedAt' => $ticket->first_responded_at?->toIso8601String(),
                'replies' => $ticket->replies
                    ->map(fn (TicketReply $reply): array => [
                        'id' => $reply->id,
                        'author' => $reply->author_name,
                        'fromStaff' => $reply->isFromStaff(),
                        'isInternal' => $reply->is_internal,
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
                'transitions' => array_map(
                    static fn (TicketStatus $status): array => [
                        'value' => $status->value,
                        'label' => (string) __($status->labelKey()),
                    ],
                    $ticket->status->allowedTransitions(),
                ),
            ],
            'options' => [
                'departments' => Department::query()->orderBy('name')->get()
                    ->map(static fn (Department $department): array => [
                        'value' => $department->id,
                        'label' => $department->name,
                    ])
                    ->values()
                    ->all(),
                'agents' => StaffUser::query()->orderBy('name')->get()
                    ->map(static fn (StaffUser $staff): array => [
                        'value' => $staff->id,
                        'label' => $staff->displayName(),
                    ])
                    ->values()
                    ->all(),
                'canned' => CannedResponse::query()->orderBy('name')->get()
                    ->map(static fn (CannedResponse $response): array => [
                        'id' => $response->id,
                        'name' => $response->name,
                        'body' => $response->body,
                    ])
                    ->values()
                    ->all(),
                'priorities' => array_values(array_map(
                    static fn (TicketPriority $priority): array => [
                        'value' => $priority->value,
                        'label' => (string) __($priority->labelKey()),
                    ],
                    TicketPriority::cases(),
                )),
            ],
            'can' => ['manage' => $this->actor->can('update', $ticket)],
        ]);
    }

    public function reply(
        TicketReplyRequest $request,
        Ticket $ticket,
        ReplyToTicket $replies,
        StoreAttachment $attachments,
    ): RedirectResponse {
        $this->authorize('update', $ticket);

        /** @var StaffUser $author */
        $author = $this->actor->model();
        $internal = $request->boolean('internal');

        $reply = $replies->fromStaff($ticket, $author, $request->string('body')->toString(), $internal);

        foreach ((array) $request->file('attachments', []) as $file) {
            $attachments->handle($ticket, $file, $reply);
        }

        return back()->with('status', __($internal ? 'support.tickets.noted' : 'support.tickets.replied'));
    }

    public function update(
        Request $request,
        Ticket $ticket,
        TransitionTicket $transitions,
    ): RedirectResponse {
        $this->authorize('update', $ticket);

        $attributes = array_filter([
            'department_id' => $request->input('department_id'),
            'assigned_to' => $request->input('assigned_to'),
            'priority' => $request->input('priority'),
        ], static fn (mixed $value): bool => $value !== null);

        if ($attributes !== []) {
            $ticket->update($attributes);
        }

        $status = $request->input('status');

        if (is_string($status) && $status !== '') {
            $transitions->handle($ticket, TicketStatus::from($status), $this->actor->model());
        }

        return back()->with('status', __('support.tickets.saved'));
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
            'priority' => $ticket->priority->value,
            'priorityLabel' => (string) __($ticket->priority->labelKey()),
            'customer' => $ticket->customer?->displayName(),
            'department' => $ticket->department?->name,
            'assignee' => $ticket->assignee?->displayName(),
            'openedAt' => $ticket->created_at?->toIso8601String(),
            'lastReplyAt' => $ticket->last_reply_at?->toIso8601String(),
            'dueAt' => $ticket->first_response_due_at?->toIso8601String(),
            'minutesUntilDue' => $ticket->minutesUntilFirstResponseDue(),
            'hasBreached' => $ticket->hasBreachedFirstResponse(),
        ];
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    private function statuses(): array
    {
        return array_values(array_map(
            static fn (TicketStatus $status): array => [
                'value' => $status->value,
                'label' => (string) __($status->labelKey()),
            ],
            TicketStatus::cases(),
        ));
    }
}
