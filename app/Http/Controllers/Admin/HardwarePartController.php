<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Application\Dcim\FitPart;
use App\Domain\Dcim\PartKind;
use App\Http\Controllers\Controller;
use App\Infrastructure\Dcim\Models\HardwarePart;
use App\Infrastructure\Dcim\Models\PartFitting;
use App\Infrastructure\Identity\Models\StaffUser;
use App\Infrastructure\Provisioning\Models\Server;
use App\Support\Audit\Facades\Audit;
use App\Support\Errors\ForbiddenException;
use App\Support\Identity\CurrentActor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Parts, and where each of them has been (§11).
 *
 * **The list is about parts, not machines**, which is the whole of it: a disk
 * outlives the machine it was first fitted to, and "where has this serial
 * been" is what a warranty claim turns on.
 *
 * **Out of warranty is on the row**, because it is the reason somebody opened
 * this screen. A part with no warranty date shows neither in nor out: that is
 * a gap in the register, and drawing it as expired would send somebody to
 * argue with a vendor who is still obliged.
 */
final class HardwarePartController extends Controller
{
    public function __construct(private readonly FitPart $parts) {}

    public function index(Request $request, CurrentActor $actor): Response
    {
        $this->refuseUnless($actor, 'dcim.view');

        $kind = PartKind::tryFrom($request->string('kind')->toString());
        $search = $request->string('q')->toString();

        $parts = HardwarePart::query()
            ->with(['currentFitting.server'])
            ->when($kind instanceof PartKind, static fn ($query) => $query->where('kind', $kind?->value))
            ->when($search !== '', static fn ($query) => $query->where(
                static fn ($inner) => $inner
                    ->where('serial', 'like', '%'.$search.'%')
                    ->orWhere('asset_tag', 'like', '%'.$search.'%')
                    ->orWhere('model', 'like', '%'.$search.'%'),
            ))
            // Soonest to lapse first, and the ones nobody recorded a
            // warranty for last: those are a gap in the register rather
            // than something about to happen, and this screen is opened
            // for the things about to happen.
            ->orderByRaw('warranty_until IS NULL, warranty_until')
            ->paginate(50)
            ->withQueryString();

        return Inertia::render('Admin/Dcim/Parts', [
            'parts' => [
                'data' => array_map($this->row(...), array_values($parts->items())),
                'links' => $parts->linkCollection()->toArray(),
                'currentPage' => $parts->currentPage(),
                'lastPage' => $parts->lastPage(),
                'total' => $parts->total(),
            ],
            'filters' => ['kind' => $kind instanceof PartKind ? $kind->value : '', 'q' => $search],
            'kinds' => array_map(
                static fn (PartKind $one): array => [
                    'value' => $one->value,
                    'label' => (string) __($one->labelKey()),
                ],
                PartKind::cases(),
            ),
            'servers' => $actor->can('dcim.parts.manage')
                ? Server::query()->orderBy('name')->get()
                    ->map(fn (Server $server): array => ['id' => $server->id, 'name' => $server->name])
                    ->values()->all()
                : [],
            'can' => ['manage' => $actor->can('dcim.parts.manage')],
        ]);
    }

    public function show(CurrentActor $actor, HardwarePart $part): Response
    {
        $this->refuseUnless($actor, 'dcim.view');

        $part->load(['fittings' => fn ($query) => $query
            ->with(['server', 'fittedBy'])
            ->orderByDesc('fitted_at')
            ->orderByDesc('id')]);

        return Inertia::render('Admin/Dcim/Part', [
            'part' => $this->row($part),
            // The whole reason the table exists: a disk that has been in
            // three machines has three rows.
            'history' => $part->fittings->map(fn (PartFitting $fitting): array => [
                'id' => $fitting->id,
                'server' => $fitting->server?->name,
                'fittedAt' => $fitting->fitted_at->toIso8601String(),
                'removedAt' => $fitting->removed_at?->toIso8601String(),
                'by' => $fitting->fittedBy?->name,
                'note' => $fitting->note,
            ])->values()->all(),
            'servers' => $actor->can('dcim.parts.manage')
                ? Server::query()->orderBy('name')->get()
                    ->map(fn (Server $server): array => ['id' => $server->id, 'name' => $server->name])
                    ->values()->all()
                : [],
            'can' => ['manage' => $actor->can('dcim.parts.manage')],
        ]);
    }

    public function store(Request $request, CurrentActor $actor): RedirectResponse
    {
        $this->refuseUnless($actor, 'dcim.parts.manage');

        $data = $request->validate([
            'kind' => ['required', Rule::enum(PartKind::class)],
            'model' => ['nullable', 'string', 'max:120'],
            'serial' => ['nullable', 'string', 'max:191'],
            'asset_tag' => ['nullable', 'string', 'max:64'],
            'vendor' => ['nullable', 'string', 'max:120'],
            'purchased_on' => ['nullable', 'date'],
            // Deliberately not `after:today`: a part whose warranty ran out
            // last year is exactly the one an operator needs to record.
            'warranty_until' => ['nullable', 'date'],
        ]);

        $staff = $this->staff($actor);

        $part = HardwarePart::query()->create([
            'organization_id' => $staff->organization_id,
            'kind' => $data['kind'],
            'model' => $data['model'] ?? null,
            // `validate()` returns only the keys that were submitted, so a
            // field the form left empty is absent rather than null — read
            // with `??` or it is a 500.
            'serial' => $data['serial'] ?? null,
            'asset_tag' => $data['asset_tag'] ?? null,
            'vendor' => $data['vendor'] ?? null,
            'purchased_on' => $data['purchased_on'] ?? null,
            'warranty_until' => $data['warranty_until'] ?? null,
        ]);

        Audit::action('dcim.part.created')
            ->by($staff)
            ->on($part)
            ->forOrganization($part->organization_id)
            ->withMetadata(['kind' => $part->kind->value])
            ->write();

        return back()->with('status', __('dcim.parts.created'));
    }

    public function fit(Request $request, CurrentActor $actor, HardwarePart $part): RedirectResponse
    {
        $this->refuseUnless($actor, 'dcim.parts.manage');

        $data = $request->validate([
            'server_id' => ['required', 'string', 'exists:servers,id'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $server = Server::query()->whereKey($data['server_id'])->firstOrFail();

        $this->parts->fit($part, $server, $this->staff($actor), $data['note'] ?? null);

        return back()->with('status', __('dcim.parts.fitted'));
    }

    public function remove(Request $request, CurrentActor $actor, HardwarePart $part): RedirectResponse
    {
        $this->refuseUnless($actor, 'dcim.parts.manage');

        $data = $request->validate(['note' => ['nullable', 'string', 'max:500']]);

        $this->parts->remove($part, $this->staff($actor), $data['note'] ?? null);

        return back()->with('status', __('dcim.parts.removed'));
    }

    /**
     * @return array<string, mixed>
     */
    private function row(HardwarePart $part): array
    {
        $fitting = $part->currentFitting;

        return [
            'id' => $part->id,
            'kind' => $part->kind->value,
            'kindLabel' => (string) __($part->kind->labelKey()),
            'model' => $part->model,
            'serial' => $part->serial,
            'assetTag' => $part->asset_tag,
            'vendor' => $part->vendor,
            'purchasedOn' => $part->purchased_on?->toDateString(),
            'warrantyUntil' => $part->warranty_until?->toDateString(),
            // Three answers, not two: null is "nobody recorded a warranty",
            // which is a gap in the register rather than an expiry.
            'outOfWarranty' => $part->isOutOfWarranty(),
            'server' => $fitting?->server?->name,
            'serverId' => $fitting?->server_id,
            'fittedAt' => $fitting?->fitted_at->toIso8601String(),
        ];
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
