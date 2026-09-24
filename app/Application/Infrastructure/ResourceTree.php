<?php

declare(strict_types=1);

namespace App\Application\Infrastructure;

use App\Domain\Infrastructure\Relation;
use App\Infrastructure\Resources\Models\ResourceEdge;
use App\Infrastructure\Resources\Models\ResourceNode;
use Illuminate\Support\Collection;

/**
 * What is inside this, and what this is inside.
 *
 * **One query per level, not one recursive CTE**, and the reason is the boundary.
 * MariaDB has had `WITH RECURSIVE` for years and it would do this in a single
 * round trip — but hand-written SQL carries no global scope, so the organization
 * filter would have to be written into the statement by hand, correctly, forever.
 * An unscoped lookup is the one class of bug this platform will not trade
 * anything for, and the price here is a handful of extra queries on a screen
 * somebody opens deliberately. Depth is bounded at twelve, so it is a handful
 * rather than an unknown number.
 *
 * Cycles are broken by remembering what has been visited. Two switches each
 * reporting the other as upstream is not a hypothetical, and neither is a rack
 * accidentally placed inside itself by an import.
 */
final readonly class ResourceTree
{
    /**
     * Everything below this node, in the order a screen should print it.
     *
     * @return list<TreeRow>
     */
    public function below(
        ResourceNode $root,
        int $maxDepth = ResourceGraph::MaxDepth,
        bool $impactOnly = false,
    ): array {
        return $this->walk($root, $maxDepth, downward: true, impactOnly: $impactOnly);
    }

    /**
     * Everything this node sits on: the rack, the row, the datacenter, the
     * hypervisor, the server.
     *
     * Upward is a tree rather than a chain, and that is not a complication to be
     * simplified away: a server is *contained* by a rack and *powered* by a PDU
     * at the same time, and an engineer looking at a dead server needs both.
     *
     * @return list<TreeRow>
     */
    public function above(ResourceNode $node, int $maxDepth = ResourceGraph::MaxDepth): array
    {
        return $this->walk($node, $maxDepth, downward: false, impactOnly: false);
    }

    /**
     * @return list<TreeRow>
     */
    private function walk(ResourceNode $start, int $maxDepth, bool $downward, bool $impactOnly): array
    {
        $rows = [new TreeRow($start, 0)];
        $seen = [$start->id => true];
        $frontier = [$start->id];
        $depth = 0;

        while ($frontier !== [] && $depth < max(1, $maxDepth)) {
            $depth++;

            $edges = $this->edgesFrom($frontier, $downward, $impactOnly);

            if ($edges->isEmpty()) {
                break;
            }

            $nextFrontier = [];

            foreach ($edges as $edge) {
                $otherId = $downward ? $edge->to_node_id : $edge->from_node_id;

                if (isset($seen[$otherId])) {
                    continue;
                }

                // Eager loaded above, and null when the boundary hid the far end
                // — a node the acting organization may not see comes back as an
                // unresolved relation rather than as an error. Defensive rather
                // than expected: an edge belongs to its container, so a customer
                // is normally stopped one step earlier, by the edge itself.
                $other = $downward ? $edge->to : $edge->from;

                if (! $other instanceof ResourceNode) {
                    continue;
                }

                $seen[$otherId] = true;
                $nextFrontier[] = $otherId;

                $rows[] = new TreeRow(
                    $other,
                    $depth,
                    $edge->relation,
                    $downward ? $edge->from_node_id : $edge->to_node_id,
                );
            }

            $frontier = $nextFrontier;
        }

        return $this->inPrintOrder($rows, $start->id);
    }

    /**
     * @param  list<string>  $nodeIds
     * @return Collection<int, ResourceEdge>
     */
    private function edgesFrom(array $nodeIds, bool $downward, bool $impactOnly): Collection
    {
        $query = ResourceEdge::query()
            ->whereIn($downward ? 'from_node_id' : 'to_node_id', $nodeIds)
            ->whereNull('ended_at')
            // Eager loaded because the loop reads the far node of every edge,
            // and a level with two edges in it would otherwise be the lazy-load
            // violation `LazyLoadingTest` exists to catch.
            ->with($downward ? 'to' : 'from');

        if ($impactOnly) {
            $query->whereIn('relation', $this->propagatingRelations());
        }

        return $query->orderBy('observed_at')->get();
    }

    /**
     * @return list<string>
     */
    private function propagatingRelations(): array
    {
        return array_values(array_map(
            static fn (Relation $relation): string => $relation->value,
            array_filter(
                Relation::cases(),
                static fn (Relation $relation): bool => $relation->propagatesImpact(),
            ),
        ));
    }

    /**
     * Depth-first order, built from the breadth-first walk.
     *
     * The walk is level by level because that is one query per level; the screen
     * wants parent-then-children so that indentation reads as nesting. Sorting
     * afterwards is cheaper than a walk that queries per node.
     *
     * @param  list<TreeRow>  $rows
     * @return list<TreeRow>
     */
    private function inPrintOrder(array $rows, string $rootId): array
    {
        /** @var array<string, list<TreeRow>> $children */
        $children = [];

        foreach ($rows as $row) {
            if ($row->parentId === null) {
                continue;
            }

            $children[$row->parentId][] = $row;
        }

        $ordered = [];
        $stack = array_values(array_filter(
            $rows,
            static fn (TreeRow $row): bool => $row->parentId === null && $row->node->id === $rootId,
        ));

        while ($stack !== []) {
            $row = array_shift($stack);
            $ordered[] = $row;

            $stack = [...($children[$row->node->id] ?? []), ...$stack];
        }

        return $ordered;
    }
}
