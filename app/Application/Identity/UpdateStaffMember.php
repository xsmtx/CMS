<?php

declare(strict_types=1);

namespace App\Application\Identity;

use App\Domain\Access\RoleScope;
use App\Domain\Identity\AccountStatus;
use App\Infrastructure\Identity\Models\StaffUser;
use App\Support\Audit\Facades\Audit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

final readonly class UpdateStaffMember
{
    public function __construct(private SessionRegistry $sessions) {}

    public function handle(StaffUser $staff, StaffAttributes $attributes, ?Model $actor = null): StaffUser
    {
        $before = $staff->only(['name', 'email', 'status']);

        DB::transaction(function () use ($staff, $attributes): void {
            $staff->update([
                'name' => $attributes->name,
                'email' => $attributes->email,
                'status' => $attributes->status->value,
            ]);

            $staff->roles()->sync(RoleIds::permitted($attributes->roleIds, RoleScope::Staff));
            $staff->flushPermissionCache();
        });

        // Suspension that leaves live sessions running is cosmetic until the
        // cookie expires, so the sessions go with it.
        if ($attributes->status !== AccountStatus::Active) {
            $this->sessions->revokeOthers($staff, '');
        }

        Audit::action('identity.staff.updated')
            ->by($actor)
            ->on($staff)
            ->changed($before, $staff->only(['name', 'email', 'status']))
            ->write();

        return $staff;
    }
}
