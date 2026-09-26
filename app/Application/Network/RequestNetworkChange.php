<?php

declare(strict_types=1);

namespace App\Application\Network;

use App\Application\Infrastructure\AdapterRegistry;
use App\Application\Infrastructure\RegisteredAdapter;
use App\Domain\Infrastructure\Capability;
use App\Domain\Infrastructure\Contracts\NetworkDeviceProvider;
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
}
