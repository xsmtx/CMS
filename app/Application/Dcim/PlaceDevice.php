<?php

declare(strict_types=1);

namespace App\Application\Dcim;

use App\Domain\Dcim\Exceptions\RackRefused;
use App\Infrastructure\Dcim\Models\Rack;
use App\Infrastructure\Dcim\Models\RackPosition;
use App\Infrastructure\Identity\Models\StaffUser;
use App\Infrastructure\Provisioning\Models\Server;
use App\Support\Audit\Facades\Audit;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Puts something in a rack, or takes it out (§11).
 *
 * **The overlap rule lives here because no index can express it.** A unique
 * key on `(rack_id, start_unit)` stops two things starting on the same unit
 * and says nothing about a 2U device landing on top of the 1U above it — so
 * the check is read-then-write under a lock on the rack row, and the index is
 * the guard behind it for two requests arriving at once. That is the same
 * shape `AllocateAddress` uses, and for the same reason: the thing being
 * claimed has no row of its own to lock.
 *
 * **A device that does not fit is refused by name.** "Unit 41 plus three
 * units is past the top of a 42U rack" is a sentence somebody can act on;
 * silently clamping it would put a machine where it is not.
 *
 * **A server may be in one rack at a time.** It is one machine. Moving it is
 * a delete and an insert, which this does in one call so a half-moved server
 * cannot exist.
 */
final readonly class PlaceDevice
{
    public function place(
        Rack $rack,
        int $startUnit,
        int $height,
        ?Server $server,
        ?string $label,
        StaffUser $actor,
    ): RackPosition {
        if ($height < 1) {
            throw RackRefused::badHeight();
        }

        if ($startUnit < 1 || $startUnit + $height - 1 > $rack->units) {
            throw RackRefused::doesNotFit($rack->name, $startUnit, $height, $rack->units);
        }

        if ($server === null && ($label === null || trim($label) === '')) {
            // Neither a machine nor a name for one. A blank row in an
            // elevation is a unit an operator will think is free.
            throw RackRefused::nothingToPlace();
        }

        $position = DB::transaction(function () use ($rack, $startUnit, $height, $server, $label): RackPosition {
            // The rack row, not the positions: the units being claimed have no
            // rows of their own, so there is nothing else two callers could
            // both hold.
            Rack::query()->whereKey($rack->id)->lockForUpdate()->first();

            $this->refuseOverlap($rack, $startUnit, $height);

            if ($server instanceof Server) {
                // One machine is in one rack. Moving it is a delete and an
                // insert, done here so a half-moved server cannot exist.
                RackPosition::query()->where('server_id', $server->id)->delete();
            }

            try {
                return RackPosition::query()->create([
                    'organization_id' => $rack->organization_id,
                    'rack_id' => $rack->id,
                    'start_unit' => $startUnit,
                    'unit_height' => $height,
                    'server_id' => $server?->id,
                    'label' => $server instanceof Server ? null : trim((string) $label),
                ]);
            } catch (QueryException) {
                // Two requests at once. The index caught what the lock was
                // meant to; tested at the guard rather than by racing
                // threads.
                throw RackRefused::occupied($rack->name, $startUnit);
            }
        });

        Audit::action('dcim.device.placed')
            ->by($actor)
            ->on($position)
            ->forOrganization($rack->organization_id)
            ->withMetadata([
                'rack' => $rack->name,
                'start_unit' => $startUnit,
                'units' => $height,
            ])
            ->write();

        return $position;
    }

    public function remove(RackPosition $position, StaffUser $actor): void
    {
        $rack = $position->rack;

        Audit::action('dcim.device.removed')
            ->by($actor)
            ->on($position)
            ->forOrganization($position->organization_id)
            ->withMetadata([
                'rack' => $rack?->name,
                'start_unit' => $position->start_unit,
                'device' => $position->displayName(),
            ])
            ->write();

        $position->delete();
    }

    /**
     * Anything already standing where this would go.
     */
    private function refuseOverlap(Rack $rack, int $startUnit, int $height): void
    {
        $end = $startUnit + $height - 1;

        $clash = RackPosition::query()
            ->withoutGlobalScope('organization')
            ->where('rack_id', $rack->id)
            ->get()
            ->first(static fn (RackPosition $other): bool => $other->start_unit <= $end
                && $other->endUnit() >= $startUnit);

        if ($clash instanceof RackPosition) {
            throw RackRefused::overlaps($clash->displayName(), $clash->start_unit, $clash->endUnit());
        }
    }
}
