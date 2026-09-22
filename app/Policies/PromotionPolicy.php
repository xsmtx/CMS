<?php

declare(strict_types=1);

namespace App\Policies;

use App\Infrastructure\Identity\Models\StaffUser;
use App\Infrastructure\Promotions\Models\Promotion;
use App\Policies\Concerns\ChecksOrganizationBoundary;

final class PromotionPolicy
{
    use ChecksOrganizationBoundary;

    public function viewAny(StaffUser $actor): bool
    {
        return $actor->hasPermissionTo('promotions.view');
    }

    public function view(StaffUser $actor, Promotion $promotion): bool
    {
        return $actor->hasPermissionTo('promotions.view')
            && $this->withinBoundary($actor, $promotion);
    }

    public function create(StaffUser $actor): bool
    {
        return $actor->hasPermissionTo('promotions.manage');
    }

    public function update(StaffUser $actor, Promotion $promotion): bool
    {
        return $actor->hasPermissionTo('promotions.manage')
            && $this->withinBoundary($actor, $promotion);
    }

    /**
     * A code that has been redeemed is part of the record of what customers
     * were charged, so it is deactivated rather than deleted.
     */
    public function delete(StaffUser $actor, Promotion $promotion): bool
    {
        return $this->update($actor, $promotion)
            && $promotion->redemptions()->doesntExist();
    }
}
