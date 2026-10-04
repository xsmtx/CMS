<?php

declare(strict_types=1);

namespace App\Application\Network;

use App\Application\Infrastructure\AdapterRegistry;
use App\Application\Infrastructure\RegisteredAdapter;
use App\Domain\Infrastructure\Capability;
use App\Domain\Infrastructure\Contracts\InfrastructureAsCodeProvider;
use App\Domain\Infrastructure\Contracts\NetworkDeviceProvider;
use App\Domain\Network\ChangeTarget;
use App\Domain\Network\Exceptions\ChangeRefused;
use App\Domain\Network\NetworkChangeState;
use App\Infrastructure\Identity\Models\StaffUser;
use App\Infrastructure\Network\Models\NetworkChange;
use App\Infrastructure\Resources\Models\ResourceNode;
use App\Support\Audit\Facades\Audit;
use Illuminate\Support\Facades\DB;

/**
 * Write down a change to a device's configuration, with a reason.
 *
 * The first step of §6's workflow, and it does three of the steps at once
 * because they are arithmetic rather than waiting: validate, read the device,
 * compute the diff. A record that had to be walked forward through three
 * states before anybody could look at it would be three buttons for no gain.
 *
 * **Reading the device happens here and again before the apply.** The diff an
 * approver reads is against the box as it was when the change was asked for;
 * the fingerprint of that reading is stored, and `ApplyNetworkChange` refuses
 * if the box has moved since. A diff computed at request time and applied an
 * hour later is a diff against a device somebody else has edited.
 *
 * **A device nothing can read is a change that cannot be requested.** Refusing
 * here rather than at the apply is the honest place: an operator writing a
 * change against a box this installation cannot reach should find that out
 * while they are still typing, not after an approval.
 *
 * `requires_approval` is copied onto the row rather than read at decision
 * time, like an issued invoice's bill-to party (ADR 0023): the rule that
 * applied when somebody asked is the rule that governs the request.
 *
 * **A workspace is the same shape with a different reading step** (§25). The
 * tool produces the plan rather than this class computing a diff, because only
 * the tool knows what its own plan means — and the state serial takes the
 * fingerprint's place, since a workspace's state is a file with a version
 * rather than text that hashes.
 */
final readonly class RequestNetworkChange
{
    public function __construct(private AdapterRegistry $registry) {}

    public function handle(
        ResourceNode $device,
        StaffUser $requester,
        string $summary,
        string $reason,
        string $intended,
        ?string $ticket = null,
    ): NetworkChange {
        if (trim($intended) === '') {
            throw ChangeRefused::nothingToApply();
        }

        $registered = $this->readerFor($device);
        $current = $registered->adapter();

        if (! $current instanceof NetworkDeviceProvider) {
            throw ChangeRefused::deviceNotReadable($device->node_key);
        }

        $baseline = $current->configuration($device->node_key);

        $change = new NetworkChange([
            'organization_id' => $device->organization_id,
            'resource_node_id' => $device->id,
            'state' => NetworkChangeState::Requested,
            'requested_by' => $requester->id,
            'summary' => $summary,
            'reason' => $reason,
            'ticket' => $ticket,
            'requires_approval' => (bool) config('platform.network.require_approval', true),
            'intended' => $intended,
            'baseline' => $baseline->text,
            'diff' => ConfigurationDiff::between($baseline->text, $intended),
            'fingerprint_before' => $baseline->fingerprint(),
        ]);

        // Straight to waiting when somebody else has to agree. A record that
        // sat in `requested` until a second button was pressed would be a
        // queue nobody was in.
        $change->state = $change->requires_approval
            ? NetworkChangeState::AwaitingApproval
            : NetworkChangeState::Authorized;

        DB::transaction(function () use ($change, $requester): void {
            $change->save();

            // Never the configuration itself: a device configuration carries
            // SNMP communities and pre-shared keys, and an audit row is read
            // on a screen.
            Audit::action('network.change.requested')
                ->by($requester)
                ->on($change)
                ->forOrganization($change->organization_id)
                ->because($change->reason)
                ->withMetadata([
                    'device' => $change->device?->node_key,
                    'requires_approval' => $change->requires_approval,
                ])
                ->write();
        });

        return $change;
    }

    /**
     * Write down a change to a workspace kept in code (§25).
     *
     * The plan is produced **now**, so what an approver reads is a plan
     * against the state as it stands at the moment of asking — and the serial
     * it was built against is stored, so `ApplyNetworkChange` can refuse if
     * somebody else applies something in between.
     *
     * **A plan that changes nothing is refused here.** There is nothing to
     * approve, and a record whose whole content is "no changes" would be a
     * queue entry somebody has to read and dismiss. It is a refusal rather
     * than a quiet success because the operator asked for something and
     * deserves to be told why it is not happening.
     *
     * `$ref` is passed through untouched and may be null: "whatever the
     * workspace tracks" is the ordinary case, and defaulting to `main` would
     * be this platform planning code nobody named.
     */
    public function forWorkspace(
        ResourceNode $workspace,
        StaffUser $requester,
        string $summary,
        string $reason,
        ?string $ref = null,
        ?string $ticket = null,
    ): NetworkChange {
        $registered = $this->plannerFor($workspace);

        /** @var InfrastructureAsCodeProvider $planner */
        $planner = $registered->adapter();

        // What the adapter calls it, which is not what the graph calls it.
        $key = WorkspaceKey::of($workspace);

        $plan = $planner->plan($key, $ref);

        if ($plan->changesNothing()) {
            throw ChangeRefused::nothingToPlan($workspace->node_key);
        }

        if ($plan->stateSerial === null) {
            throw ChangeRefused::noStateSerial($workspace->node_key);
        }

        $change = new NetworkChange([
            'organization_id' => $workspace->organization_id,
            'resource_node_id' => $workspace->id,
            'change_target' => ChangeTarget::Workspace->value,
            'state' => NetworkChangeState::Requested,
            'requested_by' => $requester->id,
            'summary' => $summary,
            'reason' => $reason,
            'ticket' => $ticket,
            'requires_approval' => (bool) config('platform.network.require_approval', true),
            /*
             * The ref is what was asked for, and it is what `intended` means
             * here: core holds no Terraform code, so "the configuration it
             * should have" is a revision in somebody's repository rather than
             * a block of text.
             */
            'intended' => $ref ?? '',
            'workspace_ref' => $ref,
            'plan_reference' => $plan->reference,
            'diff' => $plan->text,
            // The serial, in the column the fingerprint uses. One safety
            // check, two ways of expressing what "unchanged" means.
            'fingerprint_before' => (string) $plan->stateSerial,
        ]);

        $change->state = $change->requires_approval
            ? NetworkChangeState::AwaitingApproval
            : NetworkChangeState::Authorized;

        DB::transaction(function () use ($change, $requester, $plan): void {
            $change->save();

            Audit::action('network.change.requested')
                ->by($requester)
                ->on($change)
                ->forOrganization($change->organization_id)
                ->because($change->reason)
                ->withMetadata([
                    'workspace' => $change->device?->node_key,
                    'requires_approval' => $change->requires_approval,
                    // Three numbers rather than the plan: a plan is a
                    // transcript and an audit row is read on a screen.
                    'add' => $plan->add,
                    'change' => $plan->change,
                    'destroy' => $plan->destroy,
                ])
                ->write();
        });

        return $change;
    }

    /**
     * The adapter that may read this device, or a refusal naming why.
     */
    private function readerFor(ResourceNode $device): RegisteredAdapter
    {
        foreach ($this->registry->all($device->organization_id) as $registered) {
            if (! $registered->adapter() instanceof NetworkDeviceProvider) {
                continue;
            }

            if ($registered->permitted()->has(Capability::DeviceConfigRead)) {
                return $registered;
            }
        }

        throw ChangeRefused::deviceNotReadable($device->node_key);
    }

    /**
     * The adapter that may produce a plan for this workspace.
     *
     * `AutomationStateRead` rather than the apply capability: asking what
     * would happen is a read, and an installation that may look and not run is
     * a real and sensible configuration.
     */
    private function plannerFor(ResourceNode $workspace): RegisteredAdapter
    {
        foreach ($this->registry->all($workspace->organization_id) as $registered) {
            if (! $registered->adapter() instanceof InfrastructureAsCodeProvider) {
                continue;
            }

            if ($registered->permitted()->has(Capability::AutomationStateRead)) {
                return $registered;
            }
        }

        throw ChangeRefused::workspaceNotReadable($workspace->node_key);
    }
}
