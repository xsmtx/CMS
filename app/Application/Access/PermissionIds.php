<?php

declare(strict_types=1);

namespace App\Application\Access;

use App\Domain\Access\RoleScope;
use App\Infrastructure\Access\Models\Permission;

/**
 * Resolves requested permission slugs against the database.
 *
 * Orphaned permissions are excluded and the scope filter is applied, so a
 * crafted payload cannot attach a customer capability to a staff role.
 */
final class PermissionIds
{
    /**
     * @param  list<string>  $slugs
     * @return array<int, string>
     */
    public static function permitted(array $slugs, RoleScope $scope): array
    {
        if ($slugs === []) {
            return [];
        }

        return Permission::query()
            ->active()
            ->where('scope', $scope->value)
            ->whereIn('slug', $slugs)
            ->pluck('id')
            ->map(static fn (mixed $id): string => (string) $id)
            ->values()
            ->all();
    }
}
