<?php

declare(strict_types=1);

namespace App\Application\Infrastructure;

use App\Domain\Infrastructure\Relation;
use App\Infrastructure\Resources\Models\ResourceEdge;
use App\Infrastructure\Resources\Models\ResourceNode;
use Illuminate\Database\Eloquent\Builder;

/**
 * Who had this, and when.
 *
 * §5 requires historical ownership as a first-class fact, mostly because of IP
 * addresses: an abuse report arrives about an address and the question is who was
 * using it at 03:14 last Thursday, not who is using it now. With append-only
 * edges that question is a `where` clause rather than a feature, which is the
 * whole argument of ADR 0043 in one method.
 *
 * Both directions are returned. An address's history is its incoming edges; a
 * rack's history is its outgoing ones; and an engineer does not want to know
 * which of those they are looking at before they can ask.
 */
final readonly class OwnershipHistory
{
    /**
     * @return list<HistoryRow>
     */
    public function for(ResourceNode $node, ?Relation $relation = null, int $limit = 100): array
    {
        $query = ResourceEdge::query()
            ->where(static function (Builder $inner) use ($node): void {
                $inner->where('from_node_id', $node->id)->orWhere('to_node_id', $node->id);
            })
            ->with(['from', 'to']);

        if ($relation instanceof Relation) {
            $query->where('relation', $relation->value);
        }

        // Open edges first, then the most recently ended: "what is true now"
        // above "what was true", which is the order somebody reads it in.
        $edges = $query
            ->orderByRaw('ended_at is null desc')
            ->latest('observed_at')
            ->limit($limit)
            ->get();

        $rows = [];

        foreach ($edges as $edge) {
            $isContainer = $edge->from_node_id === $node->id;
            $other = $isContainer ? $edge->to : $edge->from;

            if (! $other instanceof ResourceNode) {
                continue;
            }

            $rows[] = new HistoryRow(
                other: $other,
                relation: $edge->relation,
                nodeIsContainer: $isContainer,
                observedAt: $edge->observed_at,
                endedAt: $edge->ended_at,
            );
        }

        return $rows;
    }
}
