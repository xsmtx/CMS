<?php

declare(strict_types=1);

namespace App\Application\Support;

use App\Application\Shared\SearchPattern;
use App\Domain\Support\TicketPriority;
use App\Domain\Support\TicketStatus;
use App\Infrastructure\Crm\Models\Customer;
use App\Infrastructure\Support\Models\Ticket;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * Finding a ticket, the way a desk actually looks for one.
 *
 * **Status takes more than one value.** "Show me everything that is not
 * answered and not closed" is the question a desk asks all day, and a
 * single-select cannot express it. When nothing is chosen the default is
 * what is waiting on us, which is what a queue is for.
 *
 * The message body is searched as well as the subject, because the fact an
 * operator remembers is usually a sentence somebody wrote rather than the
 * title they gave it.
 */
final readonly class SearchTickets
{
    /**
     * @param  array<string, mixed>  $criteria
     * @return LengthAwarePaginator<int, Ticket>
     */
    public function paginate(array $criteria, ?string $actorId = null): LengthAwarePaginator
    {
        $query = Ticket::query()->with([
            ...Customer::displayNameWith('customer'),
            'department',
            'assignee',
            'tags',
        ]);

        $statuses = $this->statuses($criteria);

        $statuses === []
            ? $query->awaitingUs()
            : $query->whereIn('status', $statuses);

        $department = $this->text($criteria, 'department');

        if ($department !== null) {
            $query->where('department_id', $department);
        }

        $priority = $this->text($criteria, 'priority');

        if ($priority !== null && TicketPriority::tryFrom($priority) instanceof TicketPriority) {
            $query->where('priority', $priority);
        }

        $assigned = $this->text($criteria, 'assigned');

        if ($assigned === 'unassigned') {
            $query->whereNull('assigned_to');
        } elseif ($assigned === 'mine') {
            $query->where('assigned_to', $actorId);
        } elseif ($assigned !== null) {
            $query->where('assigned_to', $assigned);
        }

        $number = $this->text($criteria, 'number');

        if ($number !== null) {
            $query->where(fn (Builder $inner) => $inner
                ->where('number', 'like', SearchPattern::like($number))
                ->orWhere('id', $number));
        }

        $text = $this->text($criteria, 'text');

        if ($text !== null) {
            $like = SearchPattern::like($text);

            // The subject and what was written in it. The fact somebody
            // remembers is usually a sentence, not a title.
            $query->where(fn (Builder $inner) => $inner
                ->where('subject', 'like', $like)
                ->orWhereHas('replies', fn (Builder $replies) => $replies->where('body', 'like', $like)));
        }

        $email = $this->text($criteria, 'email');

        if ($email !== null) {
            $like = SearchPattern::like($email);

            $query->where(fn (Builder $inner) => $inner
                ->whereHas('contact', fn (Builder $contact) => $contact->where('email', 'like', $like))
                ->orWhereHas('customer.contacts', fn (Builder $contacts) => $contacts->where('email', 'like', $like)));
        }

        $client = $this->text($criteria, 'client');

        if ($client !== null) {
            $like = SearchPattern::like($client);

            $query->whereHas('customer', function (Builder $customer) use ($like): void {
                $customer->where('company_name', 'like', $like)
                    ->orWhere('legal_name', 'like', $like)
                    ->orWhereHas('contacts', function (Builder $contacts) use ($like): void {
                        $contacts->where('email', 'like', $like);

                        SearchPattern::name($contacts, $like);
                    });
            });
        }

        $tag = $this->text($criteria, 'tag');

        if ($tag !== null) {
            $query->whereHas('tags', fn (Builder $tags) => $tags->where('tags.id', $tag));
        }

        if ($this->flag($criteria, 'breaching')) {
            $query->breachingSla();
        }

        // Null due dates last: not measured is not overdue.
        return $query
            ->orderByRaw('first_response_due_at IS NULL, first_response_due_at ASC')
            ->paginate(25)
            ->withQueryString();
    }

    /**
     * The statuses asked for, as a list.
     *
     * Accepts one or many, because a URL carrying `status=open` and one
     * carrying `status[]=open&status[]=on_hold` are the same question asked
     * by a bookmark and by the form.
     *
     * @param  array<string, mixed>  $criteria
     * @return list<string>
     */
    private function statuses(array $criteria): array
    {
        $value = $criteria['status'] ?? null;

        $values = is_array($value) ? $value : (is_string($value) && $value !== '' ? [$value] : []);

        return array_values(array_filter(
            array_map(static fn (mixed $one): ?string => is_string($one) ? $one : null, $values),
            static fn (?string $one): bool => $one !== null
                && TicketStatus::tryFrom($one) instanceof TicketStatus,
        ));
    }

    /**
     * @param  array<string, mixed>  $criteria
     */
    private function text(array $criteria, string $key): ?string
    {
        $value = $criteria[$key] ?? null;

        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    /**
     * @param  array<string, mixed>  $criteria
     */
    private function flag(array $criteria, string $key): bool
    {
        $value = $criteria[$key] ?? null;

        return in_array($value, [true, '1', 1, 'true'], true);
    }
}
