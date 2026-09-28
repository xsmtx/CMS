<?php

declare(strict_types=1);

namespace App\Support\Organizations;

use App\Infrastructure\Organizations\Models\Organization;

/**
 * The organizations a seller's own sweep may look at.
 *
 * A seller's customers are organizations beneath it, so anything a sweep
 * reads about a customer's service has to be findable from a run that has no
 * boundary at all — and `withoutBoundary()` is the whole installation, which
 * is exactly what a reseller must not see.
 *
 * This is the narrowing a boundary would have done, written once. It exists
 * because it was about to be written a second time: CLAUDE.md's rule about
 * `ResolveSeller` says three private copies of a boundary escape is three
 * chances to write one without the narrowing that makes it safe, and a
 * metering sweep and a backup sweep needed the identical query.
 *
 * `path` is a materialised ancestor chain, so a descendant's path begins with
 * its ancestor's and the seller is included by construction.
 */
final class OrganizationSubtree
{
    /** @var array<string, list<string>> */
    private array $memo = [];

    /**
     * @return list<string>
     */
    public function ids(string $organizationId): array
    {
        if (array_key_exists($organizationId, $this->memo)) {
            return $this->memo[$organizationId];
        }

        $organization = Organization::query()
            ->withoutGlobalScope('organization')
            ->whereKey($organizationId)
            ->first();

        if (! $organization instanceof Organization) {
            // Not memoised: a lookup that missed is a lookup that may find
            // something next time, and caching a miss is what made
            // `BillingSettings` read as a settings page that did not save.
            return [$organizationId];
        }

        return $this->memo[$organizationId] = array_values(Organization::query()
            ->withoutGlobalScope('organization')
            ->where('path', 'like', $organization->path.'%')
            ->pluck('id')
            ->all());
    }
}
