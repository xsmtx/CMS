<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Application\Dcim\PlaceDevice;
use App\Domain\Dcim\Exceptions\RackRefused;
use App\Http\Controllers\Controller;
use App\Infrastructure\Dcim\Models\Datacenter;
use App\Infrastructure\Dcim\Models\Rack;
use App\Infrastructure\Dcim\Models\RackPosition;
use App\Infrastructure\Dcim\Models\Room;
use App\Infrastructure\Identity\Models\StaffUser;
use App\Infrastructure\Provisioning\Models\Server;
use App\Support\Audit\Facades\Audit;
use App\Support\Errors\ForbiddenException;
use App\Support\Identity\CurrentActor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Where everything physically is (§11).
 *
 * **Two screens, not five.** The list is every rack with how much of it is
 * free, grouped by room, because "where is there space" is what an operator
 * opens this for. The rack screen is the elevation: every unit from the top
 * down, which is how somebody standing in front of the cabinet reads it.
 *
 * **Free space is the figure on the list.** A rack with a name and no
 * capacity beside it is a row that makes somebody open it to find out.
 */
final class DcimController extends Controller
{
    public function __construct(private readonly PlaceDevice $devices) {}

    public function index(CurrentActor $actor): Response
    {
        $this->refuseUnless($actor, 'dcim.view');

        $datacenters = Datacenter::query()
            ->with(['rooms' => fn ($query) => $query->orderBy('name')])
            ->orderBy('name')
            ->get();

        // Every rack with its positions, in one query rather than one per
        // rack: `LazyLoadingTest` exists because this is where it breaks.
        $racks = Rack::query()->with('positions')->orderBy('name')->get();

        return Inertia::render('Admin/Dcim/Index', [
            'datacenters' => $datacenters->map(fn (Datacenter $datacenter): array => [
                'id' => $datacenter->id,
                'name' => $datacenter->name,
                'code' => $datacenter->code,
                'rooms' => $datacenter->rooms->map(fn (Room $room): array => [
                    'id' => $room->id,
                    'name' => $room->name,
                    'racks' => $racks
                        ->filter(static fn (Rack $rack): bool => $rack->room_id === $room->id)
                        ->map(fn (Rack $rack): array => [
                            'id' => $rack->id,
                            'name' => $rack->name,
                            'units' => $rack->units,
                            'free' => $rack->freeUnits(),
                            'used' => $rack->units - $rack->freeUnits(),
                        ])
                        ->values()
                        ->all(),
                ])->values()->all(),
            ])->values()->all(),
            'can' => ['manage' => $actor->can('dcim.manage')],
        ]);
    }

    public function show(CurrentActor $actor, Rack $rack): Response
    {
        $this->refuseUnless($actor, 'dcim.view');

        $rack->load(['positions.server', 'room.datacenter', 'rackRow']);

        $byStart = $rack->positions->keyBy('start_unit');
        $occupied = [];

        foreach ($rack->positions as $position) {
            foreach ($position->units() as $unit) {
                $occupied[$unit] = $position;
            }
        }

        $units = [];

        // From the top down, which is how somebody standing in front of the
        // cabinet reads it — even though the units are numbered from the
        // bottom, which is how they are labelled on the rails.
        for ($unit = $rack->units; $unit >= 1; $unit--) {
            $here = $byStart->get($unit);
            $covering = $occupied[$unit] ?? null;

            $units[] = [
                'unit' => $unit,
                // Only the position's own lowest unit draws the device; the
                // ones above it are the same device continuing, which the
                // screen shows as a continuation rather than repeating the
                // name four times.
                'device' => $here === null ? null : [
                    'id' => $here->id,
                    'name' => $here->displayName(),
                    'height' => $here->unit_height,
                ],
                'covered' => $covering !== null && $here === null,
            ];
        }

        return Inertia::render('Admin/Dcim/Rack', [
            'rack' => [
                'id' => $rack->id,
                'name' => $rack->name,
                'units' => $rack->units,
                'free' => $rack->freeUnits(),
                'room' => $rack->room?->name,
                'datacenter' => $rack->room?->datacenter?->name,
                'row' => $rack->rackRow?->name,
                'note' => $rack->note,
            ],
            'units' => $units,
            'servers' => $actor->can('dcim.manage')
                ? Server::query()->orderBy('name')->get()->map(fn (Server $server): array => [
                    'id' => $server->id,
                    'name' => $server->name,
                ])->values()->all()
                : [],
            'can' => ['manage' => $actor->can('dcim.manage')],
        ]);
    }

    public function place(Request $request, CurrentActor $actor, Rack $rack): RedirectResponse
    {
        $this->refuseUnless($actor, 'dcim.manage');

        $data = $request->validate([
            'start_unit' => ['required', 'integer', 'min:1'],
            'unit_height' => ['required', 'integer', 'min:1', 'max:60'],
            'server_id' => ['nullable', 'string', 'exists:servers,id'],
            'label' => ['nullable', 'string', 'max:120'],
        ]);

        $server = isset($data['server_id'])
            ? Server::query()->whereKey($data['server_id'])->first()
            : null;

        try {
            $this->devices->place(
                $rack,
                (int) $data['start_unit'],
                (int) $data['unit_height'],
                $server,
                $data['label'] ?? null,
                $this->staff($actor),
            );
        } catch (RackRefused $refused) {
            // Every refusal reaches the form as a sentence. Anything else
            // still reaches the handler, because anything else is a bug.
            return back()->withErrors([
                'start_unit' => __($refused->key(), $refused->replacements()),
            ]);
        }

        return back()->with('status', __('dcim.placed'));
    }

    public function remove(CurrentActor $actor, Rack $rack, RackPosition $position): RedirectResponse
    {
        $this->refuseUnless($actor, 'dcim.manage');

        if ($position->rack_id !== $rack->id) {
            // Reached by id from another rack. 404 rather than 403: a refusal
            // would confirm the row exists.
            abort(404);
        }

        $this->devices->remove($position, $this->staff($actor));

        return back()->with('status', __('dcim.removed'));
    }

    public function storeRack(Request $request, CurrentActor $actor): RedirectResponse
    {
        $this->refuseUnless($actor, 'dcim.manage');

        $data = $request->validate([
            'room_id' => ['required', 'string', 'exists:rooms,id'],
            'name' => ['required', 'string', 'max:60'],
            'units' => ['required', 'integer', 'min:1', 'max:60'],
        ]);

        $room = Room::query()->whereKey($data['room_id'])->firstOrFail();

        $rack = Rack::query()->create([
            'organization_id' => $room->organization_id,
            'room_id' => $room->id,
            'name' => $data['name'],
            'units' => (int) $data['units'],
        ]);

        Audit::action('dcim.rack.created')
            ->by($this->staff($actor))
            ->on($rack)
            ->forOrganization($rack->organization_id)
            ->withMetadata(['room' => $room->name, 'units' => $rack->units])
            ->write();

        return back()->with('status', __('dcim.rack_created'));
    }

    private function staff(CurrentActor $actor): StaffUser
    {
        $staff = $actor->model();

        if (! $staff instanceof StaffUser) {
            throw new ForbiddenException(__('automation.errors.not_permitted'));
        }

        return $staff;
    }

    private function refuseUnless(CurrentActor $actor, string $permission): void
    {
        if (! $actor->can($permission)) {
            throw new ForbiddenException(__('automation.errors.not_permitted'));
        }
    }
}
