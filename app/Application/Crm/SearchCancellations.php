<?php

declare(strict_types=1);

namespace App\Application\Crm;

use App\Application\Shared\SearchPattern;
use App\Domain\Crm\CancellationStatus;
use App\Infrastructure\Crm\Models\CancellationRequest;
use App\Infrastructure\Crm\Models\Customer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * The cancellation queue, filtered.
 *
 * In the application layer for the reason the architecture tests enforce:
 * a controller that assembles a query is a controller that will eventually
 * own a rule. `SearchCustomers`, `SearchServices` and `SearchOrders` all
 * made the same move.
 *
 * **Outstanding requests unless somebody asks otherwise.** A queue that
 * opened on its own archive would hide the three things still waiting
 * behind a year of completed ones.
 */
final readonly class SearchCancellations
{
    /**
     * @param  array<string, mixed>  $criteria
     * @return LengthAwarePaginator<int, CancellationRequest>
     */
    public function paginate(array $criteria): LengthAwarePaginator
    {
        $query = CancellationRequest::query()
            ->with([...Customer::displayNameWith('customer'), 'service']);

        $status = $this->text($criteria, 'status');

        $status === null
            ? $query->where('status', CancellationStatus::Pending->value)
            : $query->where('status', $status);

        $type = $this->text($criteria, 'type');

        if ($type !== null) {
            $query->where('type', $type);
        }

        $reason = $this->text($criteria, 'reason');

        if ($reason !== null) {
            $query->where('reason', 'like', SearchPattern::like($reason));
        }

        $service = $this->text($criteria, 'service');

        if ($service !== null) {
            $query->where('service_id', 'like', SearchPattern::like($service));
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

        $domain = $this->text($criteria, 'domain');

        if ($domain !== null) {
            $query->whereHas('service', fn (Builder $inner) => $inner
                ->where('domain', 'like', SearchPattern::like($domain)));
        }

        // Oldest first: a request nobody acted on is a customer who told
        // you they were leaving and heard nothing back.
        return $query->oldest('requested_at')->paginate(25)->withQueryString();
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
}
