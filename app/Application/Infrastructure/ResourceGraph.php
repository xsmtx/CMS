<?php

declare(strict_types=1);

namespace App\Application\Infrastructure;

use App\Domain\Infrastructure\Exceptions\InvalidResource;
use App\Domain\Infrastructure\Relation;
use App\Domain\Infrastructure\ResourceKind;
use App\Infrastructure\Organizations\Models\Organization;
use App\Infrastructure\Resources\Models\ResourceEdge;
use App\Infrastructure\Resources\Models\ResourceNode;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * The only thing that writes to the graph.
 *
 * One writer, for the reason `ImportWriter` is one and `RecordPayment` is one:
 * the rules that make the graph trustworthy — a node's identity, an edge's
 * direction, an edge's organization, append-only history — are one paragraph
 * each, and a second place that wrote edges would eventually implement one of
 * them differently. A module never touches these tables; it answers questions and
 * core writes.
 *
 * Nothing here dispatches a job or fires an event. Discovery is inventory, and
 * inventory is not a business event (the rule ADR 0042 arrived at for imports).
 */
final class ResourceGraph
{
    /**
     * How far a traversal is willing to walk.
     *
     * Bounded because discovered data contains cycles — two switches each
     * reporting the other as upstream is an ordinary Tuesday — and because an
     * unbounded walk over rows an adapter wrote is a denial of service with extra
     * steps. Twelve covers the longest spine handoff #2 §2 draws, with room.
     */
    public const int MaxDepth = 12;

    /**
     * Organization paths, memoised for the length of one run.
     *
     * A projection attaches thousands of edges and every one of them checks
     * that the two ends are in the same subtree. `OrganizationBoundary`
     * memoises the same lookup for the same reason.
     *
     * @var array<string, string|null>
     */
    private array $paths = [];

    /**
     * Record that something exists, or that it still does.
     *
     * Idempotent by the unique key on `(organization_id, kind, node_key)`, which
     * is what lets a projection run twice and change nothing. A node that had
     * been retired and is seen again comes back, because that is what a server
     * returning to service looks like from here.
     *
     * @param  array<string, mixed>|null  $attributes
     */
    public function upsertNode(
        string $organizationId,
        string $kind,
        string $nodeKey,
        string $label,
        ?Model $subject = null,
        string $source = 'core',
        ?array $attributes = null,
    ): ResourceNode {
        $validated = ResourceKind::of($kind);

        if (trim($nodeKey) === '') {
            throw InvalidResource::emptyKey($validated->value);
        }

        $now = CarbonImmutable::now();

        // Written without the global scope on the read half deliberately: the
        // projection runs across the whole installation, and a lookup that
        // silently found nothing here would create a second node for a server
        // that already had one.
        $node = ResourceNode::query()
            ->withoutGlobalScope('organization')
            ->where('organization_id', $organizationId)
            ->where('kind', $validated->value)
            ->where('node_key', $nodeKey)
            ->first();

        if ($node instanceof ResourceNode) {
            $node->forceFill(array_filter([
                'label' => $label,
                'source' => $source,
                'subject_type' => $subject?->getMorphClass(),
                'subject_id' => $subject === null ? null : (string) $subject->getKey(),
                'attributes' => $attributes,
            ], static fn (mixed $value): bool => $value !== null));

            $node->last_seen_at = $now;
            $node->retired_at = null;
            $node->save();

            return $node;
        }

        return ResourceNode::query()->create([
            'organization_id' => $organizationId,
            'kind' => $validated->value,
            'node_key' => $nodeKey,
            'label' => $label,
            'source' => $source,
            'subject_type' => $subject?->getMorphClass(),
            'subject_id' => $subject === null ? null : (string) $subject->getKey(),
            'attributes' => $attributes,
            'discovered_at' => $now,
            'last_seen_at' => $now,
        ]);
    }

    /**
     * The node standing for a row, if there is one.
     */
    public function nodeFor(Model $subject): ?ResourceNode
    {
        return ResourceNode::query()
            ->withoutGlobalScope('organization')
            ->where('subject_type', $subject->getMorphClass())
            ->where('subject_id', (string) $subject->getKey())
            ->first();
    }

    /**
     * Say that the container holds the contained, from now.
     *
     * The argument names are `container` and `contained` rather than `from` and
     * `to`, and that is a safety measure rather than a style: the direction of an
     * edge decides which organization owns it and therefore who can see it, so
     * getting it backwards would be a disclosure rather than a modelling mistake
     * (ADR 0043). Two named arguments make that hard to do by accident.
     *
     * Idempotent: an open edge saying the same thing is left exactly as it is,
     * including its `observed_at`, because "since when" is the question history
     * answers and touching it would reset the answer.
     *
     * @param  array<string, mixed>|null  $attributes
     */
    public function attach(
        ResourceNode $container,
        ResourceNode $contained,
        Relation $relation,
        string $source = 'core',
        ?array $attributes = null,
        /**
         * Whether the contained node may only have one open edge of this
         * relation. A service is hosted by one server; moving it closes the old
         * edge rather than adding a second, which is what makes the history
         * legible instead of ambiguous.
         */
        bool $exclusive = false,
    ): ResourceEdge {
        if ($container->id === $contained->id) {
            throw InvalidResource::selfReference($container->node_key);
        }

        /*
         * The check that makes the unscoped writes above safe.
         *
         * Everything in this class reads and writes without the organization
         * scope, because a projection sweeps the whole installation and a
         * scoped lookup that quietly found nothing would create a second node
         * for a server that already had one. That is a justified escape hatch
         * and an escape hatch is not a guarantee: without this, an adapter
         * could link one customer's service to another customer's server and
         * the graph would happily answer questions about it.
         *
         * A container may hold something in its own subtree — a provider's
         * server hosting a customer's service is the normal case — and nothing
         * else.
         */
        $this->assertWithinSubtree($container, $contained);

        $existing = $this->openEdge($container, $contained, $relation);

        if ($existing instanceof ResourceEdge) {
            if ($attributes !== null && $existing->attributes !== $attributes) {
                $existing->attributes = $attributes;
                $existing->save();
            }

            return $existing;
        }

        if ($exclusive) {
            $this->closeIncoming($contained, $relation, exceptFrom: $container->id);
        }

        return ResourceEdge::query()->create([
            'organization_id' => $container->organization_id,
            'from_node_id' => $container->id,
            'to_node_id' => $contained->id,
            'relation' => $relation->value,
            'source' => $source,
            'attributes' => $attributes,
            'observed_at' => CarbonImmutable::now(),
        ]);
    }

    /**
     * Say that it is no longer true, without pretending it never was.
     *
     * Closing rather than deleting is the whole of §5's historical ownership
     * requirement: the row that ended is the record of who had the address
     * before.
     */
    public function detach(ResourceNode $container, ResourceNode $contained, Relation $relation): void
    {
        $edge = $this->openEdge($container, $contained, $relation);

        if (! $edge instanceof ResourceEdge) {
            return;
        }

        $edge->ended_at = CarbonImmutable::now();
        $edge->save();
    }

    /**
     * The thing is gone. Its edges are closed and its node is kept.
     *
     * A terminated service is exactly what an incident review needs to see, and
     * deleting the node would silently shorten every historical path through it.
     */
    public function retire(ResourceNode $node): void
    {
        $now = CarbonImmutable::now();

        ResourceEdge::query()
            ->withoutGlobalScope('organization')
            ->whereNull('ended_at')
            ->where(static function (Builder $query) use ($node): void {
                $query->where('from_node_id', $node->id)->orWhere('to_node_id', $node->id);
            })
            ->update(['ended_at' => $now, 'updated_at' => $now]);

        if ($node->retired_at !== null) {
            return;
        }

        $node->retired_at = $now;
        $node->save();
    }

    /**
     * The contained node must be inside the container's organization subtree.
     *
     * Compared by materialised path, which is what `OrganizationBoundary`
     * compares: the same definition of "inside", so an edge that passes here
     * is an edge whose container can actually see both ends.
     */
    private function assertWithinSubtree(ResourceNode $container, ResourceNode $contained): void
    {
        if ($container->organization_id === $contained->organization_id) {
            return;
        }

        $containerPath = $this->pathOf($container->organization_id);
        $containedPath = $this->pathOf($contained->organization_id);

        if ($containerPath === null || $containedPath === null
            || ! str_starts_with($containedPath, $containerPath)) {
            throw InvalidResource::acrossOrganizations($container->node_key, $contained->node_key);
        }
    }

    private function pathOf(string $organizationId): ?string
    {
        if (! array_key_exists($organizationId, $this->paths)) {
            $organization = Organization::query()
                ->withoutGlobalScope('organization')
                ->whereKey($organizationId)
                ->first();

            $this->paths[$organizationId] = $organization?->path;
        }

        return $this->paths[$organizationId];
    }

    private function openEdge(
        ResourceNode $container,
        ResourceNode $contained,
        Relation $relation,
    ): ?ResourceEdge {
        return ResourceEdge::query()
            ->withoutGlobalScope('organization')
            ->where('from_node_id', $container->id)
            ->where('to_node_id', $contained->id)
            ->where('relation', $relation->value)
            ->whereNull('ended_at')
            ->first();
    }

    private function closeIncoming(ResourceNode $contained, Relation $relation, string $exceptFrom): void
    {
        $now = CarbonImmutable::now();

        ResourceEdge::query()
            ->withoutGlobalScope('organization')
            ->where('to_node_id', $contained->id)
            ->where('relation', $relation->value)
            ->where('from_node_id', '!=', $exceptFrom)
            ->whereNull('ended_at')
            ->update(['ended_at' => $now, 'updated_at' => $now]);
    }
}
