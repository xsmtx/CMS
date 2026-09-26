<?php

declare(strict_types=1);

namespace App\Application\Network;

use App\Domain\Network\Exceptions\ChangeRefused;
use App\Domain\Network\NetworkChangeState;
use App\Infrastructure\Identity\Models\StaffUser;
use App\Infrastructure\Network\Models\NetworkChange;
use App\Support\Audit\Facades\Audit;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Somebody who is not the requester says yes or no.
 *
 * One class for both answers, because they are the same decision with
 * different outcomes and two classes would mean two places that check whether
 * the change is still open — and eventually one of them would not.
 *
 * **The requester cannot be the approver**, and that is enforced here rather
 * than by the permission: a permission can say who may approve and cannot say
 * *whose* change. An installation with one operator turns approval off in
 * configuration instead, which is visible on the record as
 * `requires_approval`.
 *
 * A withdrawal is the same shape from the other side, so it lives here too:
 * the requester takes their own change back, and a change that is already
 * applying cannot be taken back because it is already at the device.
 */
final readonly class DecideNetworkChange
{
    public function approve(NetworkChange $change, StaffUser $approver, ?string $note = null): NetworkChange
    {
        $this->refuseUnlessDecidable($change, $approver);

        return $this->settle(
            $change,
            NetworkChangeState::Authorized,
            $approver,
            $note,
            'network.change.approved',
        );
    }

    public function reject(NetworkChange $change, StaffUser $approver, ?string $note = null): NetworkChange
    {
        $this->refuseUnlessDecidable($change, $approver);

        return $this->settle(
            $change,
            NetworkChangeState::Rejected,
            $approver,
            $note,
            'network.change.rejected',
        );
    }

    /**
     * The requester takes it back.
     *
     * A separate outcome from `Rejected`, because an audit trail that could
     * not tell "they changed their mind" from "somebody refused it" is an
     * audit trail nobody can use to answer why a change did not happen.
     */
    public function cancel(NetworkChange $change, StaffUser $actor, ?string $note = null): NetworkChange
    {
        if (! $change->state->isWithdrawable()) {
            throw ChangeRefused::notWithdrawable($change->state->value);
        }

        return $this->settle(
            $change,
            NetworkChangeState::Cancelled,
            $actor,
            $note,
            'network.change.cancelled',
        );
    }

    private function refuseUnlessDecidable(NetworkChange $change, StaffUser $approver): void
    {
        if ($change->state !== NetworkChangeState::AwaitingApproval) {
            throw ChangeRefused::notDecidable($change->state->value);
        }

        if ($change->requested_by === $approver->id) {
            throw ChangeRefused::ownApproval();
        }
    }

    private function settle(
        NetworkChange $change,
        NetworkChangeState $state,
        StaffUser $actor,
        ?string $note,
        string $action,
    ): NetworkChange {
        return DB::transaction(function () use ($change, $state, $actor, $note, $action): NetworkChange {
            $change->state = $state;
            $change->decided_by = $actor->id;
            $change->decision_note = $note;
            $change->decided_at = CarbonImmutable::now();
            $change->save();

            Audit::action($action)
                ->by($actor)
                ->on($change)
                ->forOrganization($change->organization_id)
                ->because($note)
                ->withMetadata(['device' => $change->device?->node_key])
                ->write();

            return $change;
        });
    }
}
