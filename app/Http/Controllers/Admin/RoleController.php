<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Application\Access\CreateRole;
use App\Application\Access\DeleteRole;
use App\Application\Access\PermissionNames;
use App\Application\Access\RoleAttributes;
use App\Application\Access\UpdateRole;
use App\Domain\Access\PermissionDefinition;
use App\Domain\Access\PermissionRegistry;
use App\Domain\Access\RoleScope;
use App\Http\Controllers\Controller;
use App\Http\Requests\Identity\RoleRequest;
use App\Infrastructure\Access\Models\Role;
use App\Support\Identity\CurrentActor;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Role administration.
 *
 * The permission matrix is built from the registry rather than the database,
 * so a capability added in code appears here as soon as it is synced, with
 * its group and its risk flag intact.
 */
final class RoleController extends Controller
{
    public function __construct(
        private readonly PermissionRegistry $registry,
        private readonly PermissionNames $names,
        private readonly CurrentActor $actor,
    ) {}

    public function index(): Response
    {
        $this->authorize('viewAny', Role::class);

        return Inertia::render('Admin/Roles/Index', [
            'roles' => Role::query()
                ->withCount('permissions')
                ->orderByDesc('is_system')
                ->orderBy('name')
                ->get()
                ->map(fn (Role $role): array => [
                    'id' => $role->id,
                    'name' => $role->name,
                    'slug' => $role->slug,
                    'scope' => $role->scope->value,
                    'isSystem' => $role->is_system,
                    'isSuperAdmin' => $role->isSuperAdmin(),
                    'permissionCount' => $role->permissions_count,
                ])
                ->values()
                ->all(),
        ]);
    }

    public function create(): Response
    {
        $this->authorize('create', Role::class);

        return Inertia::render('Admin/Roles/Form', [
            'role' => null,
            'permissionGroups' => $this->permissionGroups(),
            'scopes' => $this->scopes(),
        ]);
    }

    public function store(RoleRequest $request, CreateRole $createRole): RedirectResponse
    {
        $this->authorize('create', Role::class);

        $createRole->handle($this->attributes($request), $this->actor->model());

        return to_route('admin.roles.index')->with('status', __('access.roles_saved'));
    }

    public function edit(Role $role): Response
    {
        $this->authorize('view', $role);

        return Inertia::render('Admin/Roles/Form', [
            'role' => [
                'id' => $role->id,
                'name' => $role->name,
                'slug' => $role->slug,
                'scope' => $role->scope->value,
                'description' => $role->description,
                'isSystem' => $role->is_system,
                'isSuperAdmin' => $role->isSuperAdmin(),
                'permissionSlugs' => $role->permissions()->pluck('slug')->all(),
            ],
            'permissionGroups' => $this->permissionGroups(),
            'scopes' => $this->scopes(),
        ]);
    }

    public function update(RoleRequest $request, Role $role, UpdateRole $updateRole): RedirectResponse
    {
        $this->authorize('update', $role);

        $updateRole->handle($role, $this->attributes($request), $this->actor->model());

        return to_route('admin.roles.index')->with('status', __('access.roles_saved'));
    }

    public function destroy(Role $role, DeleteRole $deleteRole): RedirectResponse
    {
        $this->authorize('delete', $role);

        $deleteRole->handle($role, $this->actor->model());

        return to_route('admin.roles.index')->with('status', __('access.roles_deleted'));
    }

    private function attributes(RoleRequest $request): RoleAttributes
    {
        /** @var list<string> $slugs */
        $slugs = $request->input('permission_slugs', []);

        return new RoleAttributes(
            name: $request->string('name')->toString(),
            slug: $request->string('slug')->toString(),
            scope: RoleScope::from($request->string('scope')->toString()),
            description: $request->input('description'),
            permissionSlugs: $slugs,
        );
    }

    /**
     * The permission matrix, grouped and labelled from the registry.
     *
     * @return array<int, array<string, mixed>>
     */
    private function permissionGroups(): array
    {
        $groups = [];

        foreach ($this->registry->grouped() as $group => $definitions) {
            $groups[] = [
                'key' => $group,
                'label' => (string) __('access.groups.'.$group),
                'permissions' => array_values(array_map(
                    fn (PermissionDefinition $definition): array => [
                        'slug' => $definition->slug,
                        'label' => $this->names->label($definition),
                        'description' => $this->names->description($definition),
                        'scope' => $definition->scope->value,
                        'highRisk' => $definition->highRisk,
                        'module' => $definition->module,
                    ],
                    $definitions,
                )),
            ];
        }

        return $groups;
    }

    /**
     * @return array<int, array<string, string>>
     */
    private function scopes(): array
    {
        return array_map(
            fn (RoleScope $scope): array => [
                'value' => $scope->value,
                'label' => (string) __($scope->labelKey()),
            ],
            RoleScope::cases(),
        );
    }
}
