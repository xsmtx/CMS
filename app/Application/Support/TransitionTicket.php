<?php

declare(strict_types=1);

namespace App\Application\Support;

use App\Application\Support\Exceptions\InvalidTicketTransition;
use App\Domain\Support\TicketStatus;
use App\Infrastructure\Support\Models\Ticket;
use App\Support\Audit\Facades\Audit;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * The one place a ticket changes status.
 */
final readonly class TransitionTicket
{
    public function handle(Ticket $ticket, TicketStatus $next, ?Model $actor = null): Ticket
    {
        $current = $ticket->status;

        if ($current === $next) {
            return $ticket;
        }

        if (! $current->canTransitionTo($next)) {
            throw InvalidTicketTransition::between($current, $next);
        }

        $attributes = ['status' => $next->value];

        if ($next === TicketStatus::Closed) {
            $attributes['resolved_at'] = CarbonImmutable::now();
        }

        if ($current === TicketStatus::Closed) {
            // Reopened. The resolution date was wrong.
            $attributes['resolved_at'] = null;
        }

        $ticket->forceFill($attributes)->save();

        Audit::action('support.ticket.'.$next->value)
            ->by($actor)
            ->on($ticket)
            ->forOrganization($ticket->organization_id)
            ->withMetadata(['from' => $current->value, 'to' => $next->value])
            ->write();

        return $ticket;
    }
}
