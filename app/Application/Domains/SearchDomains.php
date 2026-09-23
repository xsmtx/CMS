<?php

declare(strict_types=1);

namespace App\Application\Domains;

use App\Application\Shared\SearchPattern;
use App\Domain\Domains\DomainStatus;
use App\Infrastructure\Crm\Models\Customer;
use App\Infrastructure\Domains\Models\Domain;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * Finding a domain, the way an operator actually looks for one.
 *
 * In the application layer like every other list query here: a controller
 * that assembles one is a controller that will eventually own a rule.
 *
 * The registrar list is read from the rows rather than from the registry of
 * adapters, so it never offers one this installation has never used — a
 * filter that returns nothing teaches an operator to distrust the filters.
 */
final readonly class SearchDomains
{
    /**
     * @param  array<string, mixed>  $criteria
     * @return LengthAwarePaginator<int, Domain>
     */
    public function paginate(array $criteria): LengthAwarePaginator
    {
        $query = Domain::query()->with([
            ...Customer::displayNameWith('customer'),
            // Everything the expanded row reads. A detail panel that
            // lazy-loads is the same bug as a list that does; it just
            // waits until somebody opens it.
            'tld',
            'order.invoices.payments',
        ]);

        $name = $this->text($criteria, 'domain');

        if ($name !== null) {
            $query->where('name', 'like', SearchPattern::like($name));
        }

        $status = $this->text($criteria, 'status');

        if ($status !== null && DomainStatus::tryFrom($status) instanceof DomainStatus) {
            $query->where('status', $status);
        }

        $registrar = $this->text($criteria, 'registrar');

        if ($registrar !== null) {
            $query->where('registrar', $registrar);
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

        return $query->latest()->paginate(25)->withQueryString();
    }

    /**
     * The registrars this installation has actually used.
     *
     * @return list<array{value: string, label: string}>
     */
    public function registrars(): array
    {
        return array_values(Domain::query()
            ->select('registrar')
            ->whereNotNull('registrar')
            ->distinct()
            ->orderBy('registrar')
            ->pluck('registrar')
            ->map(static fn (string $registrar): array => [
                'value' => $registrar,
                'label' => $registrar,
            ])
            ->all());
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
