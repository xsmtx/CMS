<?php

declare(strict_types=1);

namespace App\Application\Support;

use App\Infrastructure\Crm\Models\Customer;
use App\Infrastructure\Organizations\Models\Organization;
use App\Infrastructure\Support\Models\Department;
use App\Support\Organizations\OrganizationContext;
use Illuminate\Support\Collection;

/**
 * The queues a customer may write to.
 *
 * A customer is an organization of its own and a department belongs to
 * whoever sells to them, so the ownership boundary — which means "this
 * customer and everything below it" — never contains one. That is the
 * whole reason `withoutBoundary` appears here: without it a customer
 * cannot open a ticket at all, because the department they picked does not
 * exist as far as their own boundary is concerned.
 *
 * Escaping the boundary is only safe because every query is immediately
 * narrowed to one organization — the seller, resolved from the customer's
 * own ancestry. An unnarrowed query would show a customer every reseller's
 * queues, which is the exact failure the boundary exists to prevent.
 *
 * Both methods run the query *inside* the callback rather than returning a
 * builder from it. A global scope is applied when the query executes, not
 * when it is built, so a builder handed back out of `withoutBoundary` is
 * scoped again by the time anyone calls `get()` on it.
 */
final readonly class SellerDepartments
{
    public function __construct(private OrganizationContext $organizations) {}

    /**
     * @return Collection<int, Department>
     */
    public function forCustomer(Customer $customer): Collection
    {
        $sellerId = $this->sellerFor($customer);

        /** @var Collection<int, Department> */
        return $this->organizations->withoutBoundary(
            static fn (): Collection => Department::query()
                ->selectable()
                ->where('organization_id', $sellerId)
                ->orderBy('position')
                ->orderBy('name')
                ->get(),
        );
    }

    /**
     * The one the customer picked, or null when it is not theirs to pick.
     */
    public function find(Customer $customer, string $id): ?Department
    {
        $sellerId = $this->sellerFor($customer);

        /** @var Department|null */
        return $this->organizations->withoutBoundary(
            static fn (): ?Department => Department::query()
                ->selectable()
                ->where('organization_id', $sellerId)
                ->whereKey($id)
                ->first(),
        );
    }

    /**
     * The organization that sells to this customer.
     *
     * Read past the boundary for the same reason the departments are: the
     * seller is the customer's parent, and a parent is never inside its
     * child's subtree.
     */
    private function sellerFor(Customer $customer): string
    {
        $organization = Organization::query()
            ->withoutGlobalScope('organization')
            ->find($customer->organization_id);

        return $organization instanceof Organization
            ? $organization->sellerId()
            : $customer->organization_id;
    }
}
