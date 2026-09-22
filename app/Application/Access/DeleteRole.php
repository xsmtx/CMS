<?php

declare(strict_types=1);

namespace App\Application\Access;

use App\Infrastructure\Access\Models\Role;
use App\Infrastructure\Access\PermissionCache;
use App\Support\Audit\Facades\Audit;
use Illuminate\Database\Eloquent\Model;

final readonly class DeleteRole
{
    public function __construct(private PermissionCache $cache) {}

    public function handle(Role $role, ?Model $actor = null): void
    {
        Audit::action('access.role.deleted')
            ->by($actor)
            ->on($role)
            ->withMetadata(['slug' => $role->slug])
            ->write();

        $role->delete();

        // Anyone who held it loses its grants immediately.
        $this->cache->flush();
    }
}
