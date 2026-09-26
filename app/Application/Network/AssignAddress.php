<?php

declare(strict_types=1);

namespace App\Application\Network;

use App\Application\Network\Exceptions\AddressingFailed;
use App\Domain\Network\AddressState;
use App\Infrastructure\Network\Models\IpAddressRecord;
use App\Infrastructure\Network\Models\IpAssignment;
use App\Support\Audit\Contracts\AuditLabel;
use App\Support\Audit\Facades\Audit;
use App\Support\Identity\CurrentActor;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Hand an address to something, and take it back.
 *
 * One place, because two would be two chances to move the address's state
 * without writing the history — and the history is the requirement (§5), not the
 * state.
 *
 * **An address has one open assignment at a time.** Assigning one that is
 * already held is a refusal rather than a second row: two customers holding one
 * address is the failure that shows up as somebody else's traffic.
 *
 * **Releasing quarantines by default.** An address released this morning and
 * handed out this afternoon arrives at its new holder already on whatever
 * blocklists the last one earned, and their mail stops working for reasons
 * nothing in this platform can explain. An operator can put it straight back in
 * the pool deliberately; the default is the safe one.
 */
final readonly class AssignAddress
{
    public function __construct(
        private CurrentActor $actor,
    ) {}

    public function handle(
        IpAddressRecord $address,
        Model $holder,
        ?string $note = null,
    ): IpAssignment {
        return DB::transaction(function () use ($address, $holder, $note): IpAssignment {
            $locked = IpAddressRecord::query()
                ->whereKey($address->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->assignment()->exists()) {
                throw AddressingFailed::alreadyAssigned($locked->address);
            }

            if (! $locked->state->isAllocatable()) {
                throw AddressingFailed::notAllocatable($locked->address, $locked->state->value);
            }

            $assignment = IpAssignment::query()->create([
                // The seller's, whose address it is - not the holder's. A
                // customer's own subtree must not own the record of the range
                // it came out of.
                'organization_id' => $locked->organization_id,
                'ip_address_id' => $locked->id,
                'holder_type' => $holder->getMorphClass(),
                'holder_id' => $holder->getKey(),
                // Copied now, like an order line copies the catalog: the
                // service may be renamed or terminated, and a history that
                // cannot say who held the address is not a history.
                'holder_label' => $this->labelFor($holder),
                'actor_label' => $this->actorLabel(),
                'note' => $note,
                'assigned_at' => CarbonImmutable::now(),
            ]);

            $locked->forceFill(['state' => AddressState::Assigned->value])->save();

            Audit::action('network.address.assigned')
                ->by($this->actor->model())
                ->on($locked)
                ->forOrganization($locked->organization_id)
                ->withMetadata([
                    'address' => $locked->address,
                    'holder' => $assignment->holder_label,
                ])
                ->write();

            return $assignment;
        });
    }

    /**
     * Take it back.
     *
     * `$quarantine` false is the operator saying they know where this address
     * has been.
     */
    public function release(IpAddressRecord $address, bool $quarantine = true, ?string $note = null): IpAssignment
    {
        return DB::transaction(function () use ($address, $quarantine, $note): IpAssignment {
            $locked = IpAddressRecord::query()
                ->whereKey($address->id)
                ->lockForUpdate()
                ->firstOrFail();

            $assignment = $locked->assignment()->first();

            if (! $assignment instanceof IpAssignment) {
                throw AddressingFailed::notAssigned($locked->address);
            }

            // Closed, never deleted.
            $assignment->forceFill([
                'released_at' => CarbonImmutable::now(),
                'note' => $note ?? $assignment->note,
            ])->save();

            $locked->forceFill([
                'state' => ($quarantine ? AddressState::Quarantined : AddressState::Available)->value,
            ])->save();

            Audit::action('network.address.released')
                ->by($this->actor->model())
                ->on($locked)
                ->forOrganization($locked->organization_id)
                ->withMetadata([
                    'address' => $locked->address,
                    'holder' => $assignment->holder_label,
                    'quarantined' => $quarantine,
                ])
                ->because($note)
                ->write();

            return $assignment;
        });
    }

    /**
     * Who did it, for the row the history keeps.
     *
     * A sweep or a provisioning job has no actor, and saying so is better than
     * attributing the assignment to whoever last signed in.
     */
    private function actorLabel(): ?string
    {
        $model = $this->actor->model();

        if (! $model instanceof Model) {
            return null;
        }

        return $this->labelFor($model);
    }

    private function labelFor(Model $holder): string
    {
        if ($holder instanceof AuditLabel) {
            return $holder->auditLabel();
        }

        $name = $holder->getAttribute('name');

        return is_string($name) && $name !== ''
            ? $name
            : class_basename($holder).' '.$holder->getKey();
    }
}
