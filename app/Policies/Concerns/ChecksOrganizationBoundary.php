<?php

declare(strict_types=1);

namespace App\Policies\Concerns;

use App\Infrastructure\Identity\Models\StaffUser;
use App\Infrastructure\Organizations\Models\Organization;
use Illuminate\Database\Eloquent\Model;

/**
 * The boundary half of every authorization decision.
 *
 * The global scope already hides rows outside the acting organization, so a
 * policy will usually only ever see records it is allowed to see. This is the
 * belt to that braces: a record reached by explicit id, from a job, or from a
 * query that legitimately bypassed the scope still gets checked.
 *
 * Authorization is `actor -> boundary -> ownership -> permission`, and a
 * policy that only asks the permission question is half a policy.
 */
trait ChecksOrganizationBoundary
{
    protected function withinBoundary(StaffUser $actor, Model $record): bool
    {
        // An organization is bounded by its own place in the hierarchy
        // rather than by an `organization_id` column.
        if ($record instanceof Organization) {
            return $this->actorOwns($actor, $record);
        }

        $organizationId = $record->getAttribute('organization_id');

        if (! is_string($organizationId)) {
            return false;
        }

        if ($organizationId === $actor->organization_id) {
            return true;
        }

        $recordOrganization = $this->organization($organizationId);

        return $recordOrganization !== null && $this->actorOwns($actor, $recordOrganization);
    }

    private function actorOwns(StaffUser $actor, Organization $organization): bool
    {
        $actorOrganization = $this->organization($actor->organization_id);

        return $actorOrganization !== null && $actorOrganization->owns($organization);
    }

    private function organization(string $id): ?Organization
    {
        return Organization::query()
            ->withoutGlobalScope('organization')
            ->where('id', $id)
            ->first();
    }
}
