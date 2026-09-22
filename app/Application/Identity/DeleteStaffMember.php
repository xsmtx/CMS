<?php

declare(strict_types=1);

namespace App\Application\Identity;

use App\Infrastructure\Identity\Models\StaffUser;
use App\Support\Audit\Facades\Audit;
use Illuminate\Database\Eloquent\Model;

final readonly class DeleteStaffMember
{
    public function __construct(private SessionRegistry $sessions) {}

    public function handle(StaffUser $staff, ?Model $actor = null): void
    {
        // Sessions first: the row is about to go, and an orphaned live
        // session would outlive the account it belonged to.
        $this->sessions->revokeOthers($staff, '');

        Audit::action('identity.staff.deleted')
            ->by($actor)
            ->on($staff)
            ->withMetadata(['email' => $staff->email])
            ->write();

        $staff->delete();
    }
}
