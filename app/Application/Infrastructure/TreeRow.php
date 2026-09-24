<?php

declare(strict_types=1);

namespace App\Application\Infrastructure;

use App\Domain\Infrastructure\Relation;
use App\Infrastructure\Resources\Models\ResourceNode;

/**
 * One line of a traversal, with how far it is from where the walk started.
 *
 * Flat with a depth rather than nested children, because a flat list is what
 * every consumer actually wants: a table indents by depth, a presenter maps over
 * it once, and Inertia serialises it without a recursive shape that has to be
 * typed on the other side. The Phase 11 lesson about presenters recursing into
 * relations applies to reading as well as to writing — a nested tree invites a
 * component to recurse into a depth the data does not have.
 */
final readonly class TreeRow
{
    public function __construct(
        public ResourceNode $node,
        /** Zero for the node the walk started from. */
        public int $depth,
        /** How this node hangs off its parent. Null at the root. */
        public ?Relation $relation = null,
        public ?string $parentId = null,
    ) {}
}
