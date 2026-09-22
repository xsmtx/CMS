<?php

declare(strict_types=1);

namespace App\Application\Identity;

use App\Domain\Access\RoleScope;
use App\Infrastructure\Access\Models\Role;

/**
 * Resolves requested role identifiers against the database.
 *
 * The scope filter is what stops a crafted payload attaching a
 * customer-scoped role to a staff account. It lives here rather than in a
 * controller so every caller gets it.
 */
final class RoleIds
{
    /**
     * @param  list<string>  $requested
     * @return array<int, string>
     */
    public static function permitted(array $requested, RoleScope $scope): array
    {
        if ($requested === []) {
            return [];
        }

        return Role::query()
            ->forScope($scope)
            ->whereIn('id', $requested)
            ->pluck('id')
            ->map(static fn (mixed $id): string => (string) $id)
            ->values()
            ->all();
    }
}
