<?php

declare(strict_types=1);

namespace App\Application\Network;

use App\Infrastructure\Resources\Models\ResourceNode;

/**
 * What the adapter calls a workspace, given what the graph calls it (§25).
 *
 * A node key is qualified by the adapter that discovered it —
 * `terraform/ws-1` — because `production` is what half an estate is called and
 * a node key is unique per organization. The adapter, though, knows only
 * `ws-1`, and unlike a storage volume this target is **handed back** on every
 * later call: a plan and an apply both name the workspace.
 *
 * So one place answers it, which is the `ResolveSeller` rule. Two private
 * copies of a string operation is two chances to write the one that splits on
 * the last slash instead of the first — and a workspace called
 * `team/production` would then be asked for as `production`, which is a
 * different workspace or none at all.
 */
final readonly class WorkspaceKey
{
    /**
     * Strip the adapter's own prefix, and nothing else.
     *
     * Split at the **first** slash, because a workspace name may contain one
     * and the adapter key may not. A node key with no slash at all is handed
     * back unchanged: it was written by something that did not qualify, and
     * guessing would be worse than passing on what we were given.
     */
    public static function of(ResourceNode $node): string
    {
        $position = mb_strpos($node->node_key, '/');

        return $position === false
            ? $node->node_key
            : mb_substr($node->node_key, $position + 1);
    }
}
