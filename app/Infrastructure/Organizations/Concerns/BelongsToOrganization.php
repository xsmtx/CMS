<?php

declare(strict_types=1);

namespace App\Infrastructure\Organizations\Concerns;

use App\Infrastructure\Organizations\Models\Organization;
use App\Infrastructure\Organizations\OrganizationBoundary;
use App\Support\Organizations\OrganizationContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/**
 * Applied to every model that belongs to an organization.
 *
 * Two guarantees, both enforced here rather than left to each query:
 *
 * 1. Reads are filtered to the acting organization's subtree.
 * 2. Writes are stamped with the acting organization, so a record cannot be
 *    created outside the boundary by forgetting a column.
 *
 * @mixin Model
 */
trait BelongsToOrganization
{
    public static function bootBelongsToOrganization(): void
    {
        static::addGlobalScope('organization', static function (Builder $query): void {
            app(OrganizationBoundary::class)->applyTo($query);
        });

        static::creating(static function (Model $model): void {
            if ($model->getAttribute('organization_id') !== null) {
                return;
            }

            $organizationId = app(OrganizationContext::class)->id();

            if ($organizationId === null) {
                throw new RuntimeException(sprintf(
                    'Cannot create [%s] outside an organization boundary. Set the organization '
                    .'explicitly or run inside OrganizationContext::runAs().',
                    $model::class,
                ));
            }

            $model->setAttribute('organization_id', $organizationId);
        });
    }

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * Include records from every organization.
     *
     * Reserved for provider-wide reporting and maintenance. Any use inside
     * request handling is a bug.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    protected function scopeAcrossOrganizations(Builder $query): Builder
    {
        return $query->withoutGlobalScope('organization');
    }
}
