<?php

declare(strict_types=1);

namespace App\Http\Api;

use App\Support\Errors\ValidationFailedException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * Pagination, filtering and sorting, from declared lists only.
 *
 * **An undeclared filter or sort is a validation error, never silence.** An
 * integrator who writes `?sort=nmae` and gets an unsorted list has no way
 * to discover the typo; one who gets a 422 naming the field fixes it in
 * thirty seconds. Silently ignoring input is the single most expensive
 * kindness an API can offer.
 *
 * Page size is capped rather than validated away. A client asking for ten
 * thousand records gets the maximum and a `meta.per_page` that tells them
 * what they actually got, which is more useful than a refusal.
 */
final readonly class QueryOptions
{
    private const int DEFAULT_PER_PAGE = 25;

    private const int MAX_PER_PAGE = 100;

    /**
     * @param  array<string, string>  $filters  Declared name → column
     * @param  list<string>  $sorts  Declared sortable columns
     */
    public function __construct(
        private array $filters = [],
        private array $sorts = [],
        private string $defaultSort = '-created_at',
    ) {}

    /**
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public function applyTo(Builder $query, Request $request): Builder
    {
        $this->assertKnownFilters($request);

        foreach ($this->filters as $name => $column) {
            $value = $request->query($name);

            if ($value === null || $value === '') {
                continue;
            }

            // A comma is a list. `?status=active,suspended` is the shape
            // every client already expects from a REST API.
            $values = array_values(array_filter(array_map(
                trim(...),
                explode(',', (string) $value),
            )));

            $query->whereIn($query->qualifyColumn($column), $values);
        }

        return $this->sort($query, $request);
    }

    public function perPage(Request $request): int
    {
        $requested = (int) $request->query('per_page', (string) self::DEFAULT_PER_PAGE);

        if ($requested < 1) {
            return self::DEFAULT_PER_PAGE;
        }

        return min($requested, self::MAX_PER_PAGE);
    }

    /**
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    private function sort(Builder $query, Request $request): Builder
    {
        $requested = (string) ($request->query('sort') ?? $this->defaultSort);

        $descending = str_starts_with($requested, '-');
        $column = ltrim($requested, '-');

        if (! in_array($column, $this->sorts, true)) {
            throw new ValidationFailedException(
                (string) __('api.errors.unknown_sort', ['field' => $column]),
                ['sort' => [(string) __('api.errors.sortable', [
                    'fields' => implode(', ', $this->sorts),
                ])]],
            );
        }

        return $query->orderBy($query->qualifyColumn($column), $descending ? 'desc' : 'asc');
    }

    private function assertKnownFilters(Request $request): void
    {
        // Everything that is not a filter, a sort or pagination. Listed
        // rather than guessed, so adding a reserved parameter is a
        // deliberate edit.
        $reserved = ['sort', 'page', 'per_page'];

        $unknown = array_values(array_diff(
            array_keys($request->query()),
            array_keys($this->filters),
            $reserved,
        ));

        if ($unknown === []) {
            return;
        }

        throw new ValidationFailedException(
            (string) __('api.errors.unknown_filter', ['field' => $unknown[0]]),
            ['filter' => [(string) __('api.errors.filterable', [
                'fields' => implode(', ', array_keys($this->filters)),
            ])]],
        );
    }
}
