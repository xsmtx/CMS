<?php

declare(strict_types=1);

namespace App\Infrastructure\Organizations;

use App\Support\Organizations\OrganizationContext;
use Illuminate\Contracts\Database\Query\Builder as QueryBuilderContract;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Translates the current organization boundary into SQL.
 *
 * One place decides what "visible" means, so tightening or widening the rule
 * is a single edit rather than an audit of every query in the codebase.
 */
final class OrganizationBoundary
{
    /** @var array<string, string|null> */
    private array $paths = [];

    public function __construct(private readonly OrganizationContext $context) {}

    /**
     * Constrain a model that carries an `organization_id`.
     *
     * @param  Builder<covariant Model>  $query
     */
    public function applyTo(Builder $query, string $column = 'organization_id'): void
    {
        if (! $this->context->hasBoundary()) {
            return;
        }

        $path = $this->currentPath();
        $qualified = $query->qualifyColumn($column);

        // An actor whose organization cannot be resolved sees nothing. Failing
        // closed is the only safe default for a tenancy boundary.
        if ($path === null) {
            $query->whereRaw('1 = 0');

            return;
        }

        $query->whereIn($qualified, $this->visibleOrganizationIds($path));
    }

    /**
     * Constrain the organizations table itself, which is bounded by its own
     * materialised path rather than by an `organization_id` column.
     *
     * @param  Builder<covariant Model>  $query
     */
    public function applyToSelf(Builder $query): void
    {
        if (! $this->context->hasBoundary()) {
            return;
        }

        $path = $this->currentPath();

        if ($path === null) {
            $query->whereRaw('1 = 0');

            return;
        }

        $query->where($query->qualifyColumn('path'), 'like', $path.'%');
    }

    /**
     * Forget memoised paths. Called when the hierarchy changes within one
     * process, which in practice means tests and the installer.
     */
    public function flush(): void
    {
        $this->paths = [];
    }

    private function currentPath(): ?string
    {
        $id = $this->context->id();

        if ($id === null) {
            return null;
        }

        if (! array_key_exists($id, $this->paths)) {
            $path = DB::table('organizations')->where('id', $id)->value('path');

            $this->paths[$id] = is_string($path) ? $path : null;
        }

        return $this->paths[$id];
    }

    private function visibleOrganizationIds(string $path): QueryBuilderContract
    {
        return DB::table('organizations')
            ->select('id')
            ->where('path', 'like', $path.'%');
    }
}
