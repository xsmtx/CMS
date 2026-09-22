<?php

declare(strict_types=1);

namespace App\Application\Identity;

use App\Domain\Access\RoleScope;
use App\Infrastructure\Identity\Models\StaffUser;
use App\Support\Audit\Facades\Audit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Create a staff account.
 *
 * No password is ever chosen by the creator. The account is activated through
 * the reset flow, so there is no secret to email, read aloud or leave in a
 * ticket.
 */
final readonly class CreateStaffMember
{
    public function handle(string $organizationId, StaffAttributes $attributes, ?Model $actor = null): StaffUser
    {
        $member = DB::transaction(function () use ($organizationId, $attributes): StaffUser {
            $member = StaffUser::query()->create([
                'organization_id' => $organizationId,
                'name' => $attributes->name,
                'email' => $attributes->email,
                'password' => Str::password(32),
                'status' => $attributes->status->value,
            ]);

            $member->roles()->sync(RoleIds::permitted($attributes->roleIds, RoleScope::Staff));

            return $member;
        });

        Audit::action('identity.staff.created')
            ->by($actor)
            ->on($member)
            ->withMetadata(['roles' => $member->roles()->pluck('slug')->all()])
            ->write();

        return $member;
    }
}
