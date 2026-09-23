<?php

declare(strict_types=1);

namespace App\Application\Shared;

use App\Domain\Organizations\OrganizationType;
use App\Infrastructure\Organizations\Models\Organization;
use App\Support\Organizations\OrganizationContext;

/**
 * Who sells to a given organization.
 *
 * A customer is an organization of its own, so the seller is an ancestor —
 * and an ancestor is never inside its descendant's boundary subtree. Three
 * things now need this answer (document numbering, support departments and
 * the dunning sequence), and three private copies of a boundary escape is
 * three chances to write one without the narrowing that makes it safe.
 *
 * The read is unscoped and the answer is a single id, which is then used to
 * narrow whatever the caller was really asking for. Nothing here returns
 * rows.
 */
final readonly class ResolveSeller
{
    public function __construct(private OrganizationContext $organizations) {}

    /**
     * The nearest non-customer ancestor, or the organization itself when it
     * is not a customer. Falls back to the given id rather than throwing:
     * an installation mid-import should not lose an invoice number over a
     * missing ancestor.
     */
    public function forOrganization(string $organizationId): string
    {
        $organization = $this->organizations->withoutBoundary(
            static fn (): ?Organization => Organization::query()
                ->withoutGlobalScope('organization')
                ->find($organizationId),
        );

        return $organization instanceof Organization
            ? $organization->sellerId()
            : $organizationId;
    }

    /**
     * Whether the seller is a reseller rather than the provider.
     *
     * Asked by pricing, which behaves differently for the two: a reseller's
     * customer pays the reseller's number, and the provider's own customer
     * pays the matrix. Lives here because this is the class that already
     * escapes the boundary to look at an ancestor, and a second escape
     * written somewhere else is a second chance to forget the narrowing.
     */
    public function isReseller(string $organizationId): bool
    {
        $organization = $this->organizations->withoutBoundary(
            static fn (): ?Organization => Organization::query()
                ->withoutGlobalScope('organization')
                ->find($organizationId),
        );

        return $organization instanceof Organization
            && $organization->type === OrganizationType::Reseller;
    }
}
