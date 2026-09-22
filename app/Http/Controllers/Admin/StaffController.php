<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Application\Identity\CreateStaffMember;
use App\Application\Identity\DeleteStaffMember;
use App\Application\Identity\StaffAttributes;
use App\Application\Identity\UpdateStaffMember;
use App\Domain\Access\RoleScope;
use App\Domain\Identity\AccountStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Identity\StaffRequest;
use App\Infrastructure\Access\Models\Role;
use App\Infrastructure\Identity\Models\StaffUser;
use App\Support\Audit\Facades\Audit;
use App\Support\Identity\CurrentActor;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Staff account administration.
 *
 * Listing is bounded by the global scope, so reseller staff see their own
 * colleagues and nobody else's without this controller doing anything. The
 * writes live in application use cases; this validates, authorizes and
 * renders.
 */
final class StaffController extends Controller
{
    public function __construct(private readonly CurrentActor $actor) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', StaffUser::class);

        $search = $request->string('search')->toString();

        $staff = StaffUser::query()
            ->with('roles:id,name,slug')
            ->when($search !== '', function (Builder $query) use ($search): void {
                $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $search).'%';

                $query->where(fn (Builder $inner) => $inner
                    ->where('name', 'like', $like)
                    ->orWhere('email', 'like', $like));
            })
            ->orderBy('name')
            ->paginate(25)
            ->withQueryString()
            ->through(fn (StaffUser $member): array => [
                'id' => $member->id,
                'name' => $member->name,
                'email' => $member->email,
                'status' => $member->status->value,
                'twoFactor' => $member->hasTwoFactorEnabled(),
                'lastLoginAt' => $member->last_login_at?->toIso8601String(),
                'roles' => $member->roles->pluck('name')->all(),
            ]);

        return Inertia::render('Admin/Staff/Index', [
            'staff' => $staff,
            'filters' => ['search' => $search],
            'can' => ['create' => $request->user('staff')?->can('create', StaffUser::class) ?? false],
        ]);
    }

    public function create(): Response
    {
        $this->authorize('create', StaffUser::class);

        return Inertia::render('Admin/Staff/Form', [
            'member' => null,
            'roles' => $this->assignableRoles(),
            'statuses' => $this->statuses(),
        ]);
    }

    public function store(StaffRequest $request, CreateStaffMember $createStaffMember): RedirectResponse
    {
        $this->authorize('create', StaffUser::class);

        // Owned by the creator's organization: a reseller administrator
        // creates reseller staff, not provider staff.
        $createStaffMember->handle(
            (string) $request->user('staff')?->organization_id,
            $this->attributes($request),
            $this->actor->model(),
        );

        return to_route('admin.staff.index')->with('status', __('identity.staff.created'));
    }

    public function edit(StaffUser $staff): Response
    {
        $this->authorize('view', $staff);

        return Inertia::render('Admin/Staff/Form', [
            'member' => [
                'id' => $staff->id,
                'name' => $staff->name,
                'email' => $staff->email,
                'status' => $staff->status->value,
                'roleIds' => $staff->roles()->pluck('roles.id')->all(),
                'twoFactor' => $staff->hasTwoFactorEnabled(),
            ],
            'roles' => $this->assignableRoles(),
            'statuses' => $this->statuses(),
        ]);
    }

    public function update(
        StaffRequest $request,
        StaffUser $staff,
        UpdateStaffMember $updateStaffMember,
    ): RedirectResponse {
        $this->authorize('update', $staff);

        $updateStaffMember->handle($staff, $this->attributes($request), $this->actor->model());

        return to_route('admin.staff.index')->with('status', __('identity.staff.updated'));
    }

    public function destroy(StaffUser $staff, DeleteStaffMember $deleteStaffMember): RedirectResponse
    {
        $this->authorize('delete', $staff);

        $deleteStaffMember->handle($staff, $this->actor->model());

        return to_route('admin.staff.index')->with('status', __('identity.staff.deleted'));
    }

    /**
     * Turn off another staff member's second factor.
     *
     * The recovery path for someone who has lost their authenticator. It is
     * high risk by definition, so it is audited with the actor named.
     */
    public function disableTwoFactor(StaffUser $staff): RedirectResponse
    {
        $this->authorize('update', $staff);

        $staff->disableTwoFactor();

        Audit::action('identity.two_factor.disabled_by_staff')
            ->by($this->actor->model())
            ->on($staff)
            ->write();

        return back()->with('status', __('identity.two_factor.disabled'));
    }

    private function attributes(StaffRequest $request): StaffAttributes
    {
        /** @var list<string> $roleIds */
        $roleIds = $request->input('role_ids', []);

        return new StaffAttributes(
            name: $request->string('name')->toString(),
            email: $request->string('email')->toString(),
            status: AccountStatus::from($request->string('status')->toString()),
            roleIds: $roleIds,
        );
    }

    /**
     * Only staff-scoped roles.
     *
     * @return array<int, array<string, mixed>>
     */
    private function assignableRoles(): array
    {
        return Role::query()
            ->forScope(RoleScope::Staff)
            ->orderBy('name')
            ->get()
            ->map(fn (Role $role): array => [
                'id' => $role->id,
                'name' => $role->name,
                'slug' => $role->slug,
                'isSystem' => $role->is_system,
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<int, array<string, string>>
     */
    private function statuses(): array
    {
        return array_map(
            fn (AccountStatus $status): array => [
                'value' => $status->value,
                'label' => (string) __($status->labelKey()),
            ],
            AccountStatus::cases(),
        );
    }
}
