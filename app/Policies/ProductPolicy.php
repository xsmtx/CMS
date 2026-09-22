<?php

declare(strict_types=1);

namespace App\Policies;

use App\Infrastructure\Catalog\Models\Product;
use App\Infrastructure\Identity\Models\StaffUser;
use App\Policies\Concerns\ChecksOrganizationBoundary;

final class ProductPolicy
{
    use ChecksOrganizationBoundary;

    public function viewAny(StaffUser $actor): bool
    {
        return $actor->hasPermissionTo('catalog.products.view');
    }

    public function view(StaffUser $actor, Product $product): bool
    {
        return $actor->hasPermissionTo('catalog.products.view')
            && $this->withinBoundary($actor, $product);
    }

    public function create(StaffUser $actor): bool
    {
        return $actor->hasPermissionTo('catalog.products.manage');
    }

    public function update(StaffUser $actor, Product $product): bool
    {
        return $actor->hasPermissionTo('catalog.products.manage')
            && $this->withinBoundary($actor, $product);
    }

    public function delete(StaffUser $actor, Product $product): bool
    {
        return $this->update($actor, $product);
    }

    /**
     * Editing a price is separable from editing a description: one changes
     * what a customer is charged, the other does not.
     */
    public function price(StaffUser $actor, Product $product): bool
    {
        return $actor->hasPermissionTo('catalog.pricing.manage')
            && $this->withinBoundary($actor, $product);
    }
}
