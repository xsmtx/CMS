<?php

declare(strict_types=1);

namespace App\Application\Import\Mappers;

use App\Application\Import\ImportMapper;
use App\Application\Import\ImportResult;
use App\Application\Import\ImportWriter;
use App\Application\Import\LegacyValues;
use App\Domain\Import\ImportDomain;
use App\Domain\Import\ImportRecord;
use App\Domain\Support\TicketPriority;
use App\Domain\Support\TicketStatus;
use App\Infrastructure\Crm\Models\Customer;
use App\Infrastructure\Support\Models\Ticket;
use App\Support\Organizations\OrganizationContext;
use Illuminate\Database\Eloquent\Model;

/**
 * A legacy ticket becomes a closed record of a conversation.
 *
 * **Every imported ticket is closed**, whatever the legacy row said, and that
 * is a decision rather than laziness. A ticket has one clock and one place that
 * moves it (ADR 0030): an imported open ticket would arrive with an SLA
 * calculated from a department this installation has not assigned, immediately
 * breached by two years, and it would sit at the top of the support queue on the
 * morning after the migration looking like an emergency.
 *
 * The conversation an operator actually needs is the history, and a closed
 * ticket with its subject and its dates is that. Anything still live on the day
 * of a migration is handled by the operator answering it in the old system,
 * which is what they were going to do anyway.
 *
 * **No department, and therefore no SLA.** `first_response_due_at` and
 * `resolution_due_at` are left null: no SLA is a real configuration here, and an
 * imported ticket with an invented deadline would be worse than one with none.
 *
 * **No replies.** A ticket's replies are a domain of their own and the legacy
 * table is large — often the largest in the database. Importing subjects and
 * dates gives an operator the index; the bodies stay where they are, and the
 * result document says so rather than implying a full transcript came across.
 */
final readonly class TicketMapper implements ImportMapper
{
    public function __construct(private OrganizationContext $organizations) {}

    public function domain(): ImportDomain
    {
        return ImportDomain::Tickets;
    }

    public function map(ImportRecord $record, ImportWriter $writer): ImportResult
    {
        $clientId = $record->text('userid');
        $customerId = $writer->mappedId(ImportDomain::Customers, $clientId);

        if ($customerId === null) {
            return ImportResult::failed("Its client [{$clientId}] was not imported.");
        }

        $customer = Customer::query()->withoutGlobalScope('organization')->find($customerId);

        if ($customer === null) {
            return ImportResult::failed('Its customer no longer exists.');
        }

        $opened = LegacyValues::date($record->get('date')) ?? LegacyValues::date($record->get('lastreply'));

        return $writer->create($this->domain(), $record->externalId, fn (): Model => $this->organizations->runAs(
            $customer->organization_id,
            fn (): Ticket => Ticket::query()->create([
                'organization_id' => $customer->organization_id,
                'number' => $record->text('tid') ?: 'IMP-'.$record->externalId,
                'customer_id' => $customer->id,
                'contact_id' => null,
                // Null on purpose: no SLA is a real configuration, and an
                // imported deadline would be an invented one.
                'department_id' => null,
                'subject' => $record->text('title', 'Imported ticket'),
                // Always closed. An imported open ticket arrives two years
                // breached and sits at the top of the queue looking urgent.
                'status' => TicketStatus::Closed->value,
                'priority' => $this->priority($record->text('priority')),
                'first_response_due_at' => null,
                'resolution_due_at' => null,
                'resolved_at' => LegacyValues::date($record->get('lastreply')) ?? $opened,
                'last_reply_at' => LegacyValues::date($record->get('lastreply')) ?? $opened,
            ]),
        ));
    }

    private function priority(string $legacy): string
    {
        return match (strtolower($legacy)) {
            'low' => TicketPriority::Low->value,
            'high' => TicketPriority::High->value,
            'urgent', 'critical' => TicketPriority::Urgent->value,
            default => TicketPriority::Normal->value,
        };
    }
}
