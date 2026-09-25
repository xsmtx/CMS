<?php

declare(strict_types=1);

namespace App\Application\Infrastructure;

use App\Infrastructure\Resources\Models\ResourceNode;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * The Explorer's list, as a use case rather than as a query in a controller.
 *
 * `App\Http` validates, authorizes, calls a use case and renders; the layering
 * test enforces it by refusing to let a controller so much as name Eloquent's
 * `Builder`. Four filters and a `where` group is exactly the kind of thing that
 * looks harmless in a controller and is a query nobody can reuse — the Telemetry
 * screen and, later, the global search both want to ask this same question.
 */
final readonly class ListResources
{
    /**
     * @return LengthAwarePaginator<int, ResourceNode>
     */
    public function handle(
        ?string $kind = null,
        ?string $health = null,
        ?string $search = null,
        bool $retired = false,
        int $perPage = 25,
    ): LengthAwarePaginator {
        return ResourceNode::query()
            ->when(
                $kind !== null && $kind !== '',
                static fn (Builder $query): Builder => $query->where('kind', $kind),
            )
            ->when(
                $health !== null && $health !== '',
                static fn (Builder $query): Builder => $query->where('health', $health),
            )
            ->when(
                $search !== null && $search !== '',
                static fn (Builder $query): Builder => $query->where(
                    static function (Builder $inner) use ($search): void {
                        $inner->where('label', 'like', '%'.$search.'%')
                            ->orWhere('node_key', 'like', '%'.$search.'%');
                    },
                ),
            )
            ->when(
                $retired,
                static fn (Builder $query): Builder => $query->whereNotNull('retired_at'),
                static fn (Builder $query): Builder => $query->whereNull('retired_at'),
            )
            ->orderBy('kind')
            ->orderBy('label')
            ->paginate($perPage)
            ->withQueryString();
    }

    /**
     * The three counts the screen opens on.
     *
     * "Nothing reporting" is the one that earns its place: a node with no
     * measurements is either something nobody has pointed an adapter at or
     * something an adapter has forgotten, and both are worth finding before an
     * incident does.
     *
     * @return array<string, int>
     */
    public function counts(): array
    {
        return [
            'nodes' => ResourceNode::query()->whereNull('retired_at')->count(),
            'unwatched' => ResourceNode::query()
                ->whereNull('retired_at')
                ->where('health', ResourceNode::HealthUnknown)
                ->count(),
            'retired' => ResourceNode::query()->whereNotNull('retired_at')->count(),
        ];
    }

    /**
     * Every kind actually present, plus core's own.
     *
     * Read from the rows rather than from a list, because a module's kinds are
     * the module's and core has no list of them. A filter offering `rack` on an
     * installation with no racks would be a filter that always returns nothing.
     *
     * @return list<string>
     */
    public function kinds(): array
    {
        $present = ResourceNode::query()
            ->select('kind')
            ->distinct()
            ->orderBy('kind')
            ->pluck('kind')
            ->all();

        return array_values(array_filter($present, is_string(...)));
    }
}
