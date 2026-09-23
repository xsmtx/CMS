<?php

declare(strict_types=1);

namespace App\Application\Support;

use App\Application\Shared\AllocateNumber;
use App\Domain\Support\Events\TicketOpened;
use App\Domain\Support\TicketPriority;
use App\Domain\Support\TicketStatus;
use App\Infrastructure\Crm\Models\Customer;
use App\Infrastructure\Identity\Models\Contact;
use App\Infrastructure\Support\Models\Department;
use App\Infrastructure\Support\Models\Ticket;
use App\Infrastructure\Support\Models\TicketReply;
use App\Support\Audit\Facades\Audit;
use App\Support\Correlation\CorrelationContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Starts a conversation, and starts its clock.
 *
 * The due dates are computed **once, here**, from the department's SLA
 * scaled by priority. Computing them on read would mean a ticket's promise
 * changing when an operator edits the department a week later, which is the
 * opposite of a promise.
 *
 * A department with no SLA produces a ticket with no due dates. That is a
 * real configuration — not every queue is measured — and the screens treat
 * a null due date as "not measured" rather than as "overdue".
 */
final readonly class OpenTicket
{
    public function __construct(
        private AllocateNumber $numbers,
        private CorrelationContext $correlation,
    ) {}

    /**
     * @param  array<string, string|null>  $links  service_id, domain_id, invoice_id
     */
    public function handle(
        Customer $customer,
        ?Contact $contact,
        ?Department $department,
        string $subject,
        string $body,
        TicketPriority $priority = TicketPriority::Normal,
        array $links = [],
    ): Ticket {
        $now = CarbonImmutable::now();

        $ticket = DB::transaction(function () use (
            $customer, $contact, $department, $subject, $body, $priority, $links, $now
        ): Ticket {
            $firstResponse = $department?->firstResponseMinutesFor($priority);
            $resolution = $department?->resolutionMinutesFor($priority);

            $ticket = Ticket::query()->create([
                'organization_id' => $customer->organization_id,
                'number' => $this->numbers->handle(
                    $customer->organization_id,
                    'ticket',
                    (string) config('platform.support.numbering.prefix', 'TKT-'),
                    (int) config('platform.support.numbering.padding', 6),
                ),
                'customer_id' => $customer->id,
                'contact_id' => $contact?->id,
                'department_id' => $department?->id,
                'service_id' => $links['service_id'] ?? null,
                'domain_id' => $links['domain_id'] ?? null,
                'invoice_id' => $links['invoice_id'] ?? null,
                'subject' => $subject,
                'status' => TicketStatus::Open->value,
                'priority' => $priority->value,
                'first_response_due_at' => $firstResponse === null
                    ? null
                    : $now->addMinutes($firstResponse),
                'resolution_due_at' => $resolution === null ? null : $now->addMinutes($resolution),
                'last_reply_at' => $now,
                'last_reply_by' => TicketReply::AUTHOR_CUSTOMER,
            ]);

            $ticket->replies()->create([
                'organization_id' => $ticket->organization_id,
                'author_type' => TicketReply::AUTHOR_CUSTOMER,
                'author_id' => $contact?->id,
                'author_name' => $contact?->displayName() ?? $customer->displayName(),
                'body' => $body,
                'is_internal' => false,
                'created_at' => $now,
            ]);

            return $ticket;
        });

        Audit::action('support.ticket.opened')
            ->by($contact)
            ->on($ticket)
            ->forOrganization($ticket->organization_id)
            ->withMetadata(['department' => $department?->name])
            ->write();

        event(new TicketOpened($ticket->id, $ticket->organization_id, $this->correlation->id()));

        return $ticket;
    }
}
