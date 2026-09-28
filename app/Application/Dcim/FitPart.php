<?php

declare(strict_types=1);

namespace App\Application\Dcim;

use App\Infrastructure\Dcim\Models\HardwarePart;
use App\Infrastructure\Dcim\Models\PartFitting;
use App\Infrastructure\Identity\Models\StaffUser;
use App\Infrastructure\Provisioning\Models\Server;
use App\Support\Audit\Facades\Audit;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Puts a part in a machine, or takes it out (§11).
 *
 * **A part is in one machine at a time**, so fitting one that is already
 * somewhere closes that fitting first — in one transaction, so a part cannot
 * be in two places or in none. It is the same shape `PlaceDevice` uses for a
 * server moving rack, and the reason is the same: a half-moved thing is a
 * register nobody can trust.
 *
 * **Nothing is ever deleted or edited.** Taking a part out closes its
 * fitting; putting it back opens a new one. A disk that has been in three
 * machines has three rows, and that is the whole point — "where has this
 * serial been" is what a warranty claim turns on.
 *
 * **Fitting a part into the machine it is already in does nothing**, rather
 * than closing and reopening the fitting. A double-press should not make it
 * look as though somebody pulled the disk and put it back.
 */
final readonly class FitPart
{
    public function fit(
        HardwarePart $part,
        Server $server,
        StaffUser $actor,
        ?string $note = null,
        ?CarbonImmutable $at = null,
    ): PartFitting {
        $at ??= CarbonImmutable::now();

        $fitting = DB::transaction(function () use ($part, $server, $actor, $note, $at): PartFitting {
            $open = PartFitting::query()
                ->withoutGlobalScope('organization')
                ->where('hardware_part_id', $part->id)
                ->whereNull('removed_at')
                ->lockForUpdate()
                ->first();

            if ($open instanceof PartFitting && $open->server_id === $server->id) {
                // Already there. A double-press must not read as somebody
                // pulling the disk and putting it back.
                return $open;
            }

            if ($open instanceof PartFitting) {
                $open->forceFill([
                    'removed_at' => $at,
                    'removed_by' => $actor->id,
                ])->save();
            }

            return PartFitting::query()->create([
                'organization_id' => $part->organization_id,
                'hardware_part_id' => $part->id,
                'server_id' => $server->id,
                'fitted_at' => $at,
                'fitted_by' => $actor->id,
                'note' => $note,
            ]);
        });

        Audit::action('dcim.part.fitted')
            ->by($actor)
            ->on($part)
            ->forOrganization($part->organization_id)
            ->because($note)
            ->withMetadata(['server' => $server->name])
            ->write();

        return $fitting;
    }

    /**
     * Take it out, and leave the history behind.
     */
    public function remove(
        HardwarePart $part,
        StaffUser $actor,
        ?string $note = null,
        ?CarbonImmutable $at = null,
    ): void {
        $at ??= CarbonImmutable::now();

        $open = PartFitting::query()
            ->withoutGlobalScope('organization')
            ->with('server')
            ->where('hardware_part_id', $part->id)
            ->whereNull('removed_at')
            ->first();

        if (! $open instanceof PartFitting) {
            // Not in anything. Taking out a part that is on a shelf is not an
            // error; it is somebody checking.
            return;
        }

        $open->forceFill([
            'removed_at' => $at,
            'removed_by' => $actor->id,
        ])->save();

        Audit::action('dcim.part.removed')
            ->by($actor)
            ->on($part)
            ->forOrganization($part->organization_id)
            ->because($note)
            ->withMetadata(['server' => $open->server?->name])
            ->write();
    }
}
