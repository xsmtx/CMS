<?php

declare(strict_types=1);

namespace App\Infrastructure\Access\Concerns;

use App\Domain\Access\PermissionDefinition;
use App\Domain\Access\PermissionRegistry;
use App\Domain\Access\RoleScope;
use App\Domain\Access\SystemRole;
use App\Infrastructure\Access\Models\Role;
use App\Infrastructure\Access\PermissionCache;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use InvalidArgumentException;

/**
 * Gives a model role-based, permission-checked authorization.
 *
 * Applied to staff users today; customer contacts and API tokens attach the
 * same trait in later phases, which is why assignments are polymorphic.
 *
 * @mixin Model
 */
trait HasRoles
{
    /**
     * The scope a subject is allowed to hold roles in. Override on the model
     * when it is not staff.
     */
    public function roleScope(): RoleScope
    {
        return RoleScope::Staff;
    }

    /**
     * @return MorphToMany<Role, $this>
     */
    public function roles(): MorphToMany
    {
        return $this->morphToMany(Role::class, 'subject', 'role_assignments', 'subject_id', 'role_id')
            ->withTimestamps();
    }

    public function assignRole(Role|SystemRole|string $role): void
    {
        $model = $this->resolveRole($role);

        if ($model->scope !== $this->roleScope()) {
            throw new InvalidArgumentException(
                "Role [{$model->slug}] is scoped to [{$model->scope->value}] and cannot be ".
                "assigned to a [{$this->roleScope()->value}] subject."
            );
        }

        $this->roles()->syncWithoutDetaching([$model->id]);
        $this->flushPermissionCache();
    }

    public function revokeRole(Role|SystemRole|string $role): void
    {
        $this->roles()->detach($this->resolveRole($role)->id);
        $this->flushPermissionCache();
    }

    public function hasRole(Role|SystemRole|string $role): bool
    {
        $slug = match (true) {
            $role instanceof Role => $role->slug,
            $role instanceof SystemRole => $role->value,
            default => $role,
        };

        return $this->roles->contains(
            static fn (Role $assigned): bool => $assigned->slug === $slug,
        );
    }

    public function isSuperAdmin(): bool
    {
        return $this->hasRole(SystemRole::SuperAdmin);
    }

    /**
     * Every permission slug this subject effectively holds.
     *
     * @return list<string>
     */
    public function effectivePermissions(): array
    {
        return $this->permissionCache()->remember($this, function (): array {
            // The super-admin role bypasses the permission check rather than
            // holding grants, so reading its assignments would describe it
            // as holding nothing at all. The interface asks this question to
            // decide what to show, and it deserves the same answer the gate
            // gives: everything in scope.
            if ($this->isSuperAdmin()) {
                return array_values(array_map(
                    static fn (PermissionDefinition $definition): string => $definition->slug,
                    app(PermissionRegistry::class)->forScope($this->roleScope()),
                ));
            }

            /** @var list<string> $slugs */
            $slugs = $this->roles()
                ->with('permissions:id,slug,orphaned_at')
                ->get()
                ->flatMap(static fn (Role $role): array => $role->permissions
                    ->whereNull('orphaned_at')
                    ->pluck('slug')
                    ->all())
                ->unique()
                ->values()
                ->all();

            return $slugs;
        });
    }

    public function hasPermissionTo(string $permission): bool
    {
        if ($this->isSuperAdmin()) {
            return true;
        }

        return in_array($permission, $this->effectivePermissions(), strict: true);
    }

    public function flushPermissionCache(): void
    {
        $this->unsetRelation('roles');
        $this->permissionCache()->forget($this);
    }

    private function resolveRole(Role|SystemRole|string $role): Role
    {
        if ($role instanceof Role) {
            return $role;
        }

        $slug = $role instanceof SystemRole ? $role->value : $role;

        return Role::query()->where('slug', $slug)->firstOrFail();
    }

    private function permissionCache(): PermissionCache
    {
        return app(PermissionCache::class);
    }
}
