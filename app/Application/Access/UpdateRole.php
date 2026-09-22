<?php

declare(strict_types=1);

namespace App\Application\Access;

use App\Infrastructure\Access\Models\Role;
use App\Infrastructure\Access\PermissionCache;
use App\Support\Audit\Facades\Audit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

final readonly class UpdateRole
{
    public function __construct(private PermissionCache $cache) {}

    public function handle(Role $role, RoleAttributes $attributes, ?Model $actor = null): Role
    {
        $before = $role->permissions()->pluck('slug')->all();

        DB::transaction(function () use ($role, $attributes): void {
            // A system role's name and description are editable; its slug and
            // scope are not, because policies and upgrades name it.
            $role->update($role->is_system
                ? ['name' => $attributes->name, 'description' => $attributes->description]
                : [
                    'name' => $attributes->name,
                    'description' => $attributes->description,
                    'slug' => $attributes->slug,
                    'scope' => $attributes->scope->value,
                ]);

            $role->permissions()->sync(PermissionIds::permitted($attributes->permissionSlugs, $role->scope));
        });

        // One role's permission set changed, so every assignee's cached set
        // is now wrong.
        $this->cache->flush();

        Audit::action('access.role.updated')
            ->by($actor)
            ->on($role)
            ->changed(['permissions' => $before], ['permissions' => $role->permissions()->pluck('slug')->all()])
            ->write();

        return $role;
    }
}
