<?php

declare(strict_types=1);

namespace App\Application\Ai;

use App\Domain\Ai\AiFeature;
use App\Domain\Ai\AiPrompt;
use App\Infrastructure\Support\Models\Ticket;
use App\Infrastructure\Support\Models\TicketReply;

/**
 * What a ticket actually sends to a vendor (ADR 0050).
 *
 * **Assembled field by field, and that is the whole safety argument.** A
 * prompt built by serialising a model would carry whatever column the next
 * phase adds, and nobody would know until it turned up in a vendor's logs.
 * `SecretRedactor` is the net, not the plan (non-negotiable 7).
 *
 * So what leaves is named here, once, and `AiPromptTest` asserts it: the
 * subject, the department's name, the product the ticket is about, and the
 * replies. Not the customer's address, not their tax id, not their phone
 * number, not an invoice, not a password reset link, not a service's
 * credentials — none of which this class can reach, because it does not take
 * them.
 *
 * **An internal note never leaves.** A colleague writing "this customer is
 * difficult, charge them for the call" wrote it for the people in this
 * installation. Sending it to a vendor would be bad enough; having it come
 * back inside a draft addressed to the customer is the version that ends up
 * in a complaint.
 *
 * The customer is named by their display name and nothing else, because a
 * draft that opens "Dear Ayşe" is the point and a draft that knows their
 * postal code is not.
 */
final readonly class TicketPrompts
{
    /**
     * How many replies travel.
     *
     * The most recent, not the first: a long thread's early messages are
     * usually the part everybody has stopped referring to, and sending all
     * forty would cost money to tell the model about a problem that was
     * solved in March.
     */
    private const int Replies = 12;

    public function reply(Ticket $ticket): AiPrompt
    {
        return new AiPrompt(
            feature: AiFeature::TicketReply,
            task: (string) __('ai.tasks.ticket_reply'),
            context: $this->context($ticket),
            maxTokens: 900,
        );
    }

    public function summary(Ticket $ticket): AiPrompt
    {
        return new AiPrompt(
            feature: AiFeature::TicketSummary,
            task: (string) __('ai.tasks.ticket_summary'),
            context: $this->context($ticket),
            // A summary that runs longer than the thread is not a summary.
            maxTokens: 300,
        );
    }

    /**
     * @return list<array{label: string, value: string}>
     */
    private function context(Ticket $ticket): array
    {
        $ticket->loadMissing(['replies', 'department', 'customer', 'service']);

        $context = [
            ['label' => 'Subject', 'value' => $ticket->subject],
        ];

        if ($ticket->department !== null) {
            $context[] = ['label' => 'Department', 'value' => $ticket->department->name];
        }

        if ($ticket->customer !== null) {
            // The display name and nothing else. A draft that opens with
            // their name is the point; one that knows their postcode is not.
            $context[] = ['label' => 'Customer', 'value' => $ticket->customer->displayName()];
        }

        if ($ticket->service !== null) {
            $context[] = ['label' => 'Service', 'value' => $ticket->service->name];
        }

        $replies = $ticket->replies
            // Never an internal note: a colleague wrote that for the people
            // in this installation, and a draft that quoted one back to the
            // customer is the version that ends up in a complaint.
            ->reject(static fn (TicketReply $reply): bool => $reply->is_internal)
            ->sortBy('created_at')
            ->values();

        foreach ($replies->slice(-self::Replies) as $reply) {
            $context[] = [
                'label' => $reply->author_type === TicketReply::AUTHOR_STAFF ? 'Us' : 'Them',
                'value' => $reply->body,
            ];
        }

        return $context;
    }
}
