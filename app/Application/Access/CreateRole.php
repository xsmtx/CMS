<?php

declare(strict_types=1);

namespace App\Application\Access;

use App\Infrastructure\Access\Models\Role;
use App\Infrastructure\Access\PermissionCache;
use App\Support\Audit\Facades\Audit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

final readonly class CreateRole
{
    public function __construct(private PermissionCache $cache) {}

    public function handle(RoleAttributes $attributes, ?Model $actor = null): Role
    {
        $role = DB::transaction(function () use ($attributes): Role {
            // Roles are installation-global in this phase; ADR 0002 records
            // why, and the owning column arrives with per-reseller roles.
            $role = Role::query()->create([
                'slug' => $attributes->slug,
                'scope' => $attributes->scope->value,
                'name' => $attributes->name,
                'description' => $attributes->description,
                'is_system' => false,
            ]);

            $role->permissions()->sync(PermissionIds::permitted($attributes->permissionSlugs, $role->scope));

            return $role;
        });

        $this->cache->flush();

        Audit::action('access.role.created')
            ->by($actor)
            ->on($role)
            ->withMetadata(['permissions' => $role->permissions()->pluck('slug')->all()])
            ->write();

        return $role;
    }
}
