<?php

declare(strict_types=1);

namespace App\Policies;

use App\Infrastructure\Identity\Models\StaffUser;
use App\Infrastructure\Shared\Models\CurrencyRecord;
use App\Policies\Concerns\ChecksOrganizationBoundary;

final class CurrencyRecordPolicy
{
    use ChecksOrganizationBoundary;

    public function viewAny(StaffUser $actor): bool
    {
        // Anyone who can see a price needs to know what currency it is in.
        return $actor->hasPermissionTo('catalog.products.view');
    }

    public function view(StaffUser $actor, CurrencyRecord $record): bool
    {
        return $this->viewAny($actor) && $this->withinBoundary($actor, $record);
    }

    public function create(StaffUser $actor): bool
    {
        return $actor->hasPermissionTo('catalog.currencies.manage');
    }

    public function update(StaffUser $actor, CurrencyRecord $record): bool
    {
        return $actor->hasPermissionTo('catalog.currencies.manage')
            && $this->withinBoundary($actor, $record);
    }

    public function delete(StaffUser $actor, CurrencyRecord $record): bool
    {
        return $this->update($actor, $record);
    }
}
