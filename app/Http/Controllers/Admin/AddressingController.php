<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Application\Network\AllocateAddress;
use App\Application\Network\AssignAddress;
use App\Application\Network\PrefixUtilisation;
use App\Application\Network\SavePrefix;
use App\Domain\Network\AddressState;
use App\Domain\Network\IpFamily;
use App\Domain\Network\IpPrefix;
use App\Domain\Network\PoolPurpose;
use App\Http\Controllers\Controller;
use App\Http\Requests\Network\PoolRequest;
use App\Http\Requests\Network\PrefixRequest;
use App\Infrastructure\Network\Models\IpAddressRecord;
use App\Infrastructure\Network\Models\IpAssignment;
use App\Infrastructure\Network\Models\IpPool;
use App\Infrastructure\Network\Models\IpPrefixRecord;
use App\Infrastructure\Network\Models\Vlan;
use App\Support\Errors\ForbiddenException;
use App\Support\Identity\CurrentActor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Addressing: what this installation has, and who is holding it.
 *
 * Two screens rather than five. A list of networks with how full each one is, and
 * one network with its addresses — because the question an operator actually
 * arrives with is either "where is there room" or "who has this address", and a
 * screen per table would answer neither without three clicks.
 *
 * A free address has no row (§5 is unbuildable otherwise: a /64 holds eighteen
 * quintillion), so the address list is what exists plus the first free one, and
 * the screen says how many are left rather than drawing them.
 */
final class AddressingController extends Controller
{
    public function __construct(
        private readonly CurrentActor $actor,
        private readonly PrefixUtilisation $utilisation,
        private readonly SavePrefix $prefixes,
        private readonly AllocateAddress $allocator,
        private readonly AssignAddress $assignments,
    ) {}

    public function index(Request $request): Response
    {
        $this->authorizeFor('network.ipam.view');

        $poolId = $request->string('pool')->toString();
        $search = trim($request->string('q')->toString());

        $prefixes = IpPrefixRecord::query()
            ->with(['pool', 'vlan'])
            ->when($poolId !== '', fn ($query) => $query->where('ip_pool_id', $poolId))
            ->when($search !== '', fn ($query) => $query->where('cidr', 'like', '%'.$search.'%'))
            ->orderBy('family')
            ->orderBy('network_bytes')
            ->paginate(25)
            ->withQueryString();

        $counts = $this->utilisation->counts(array_values(array_map(
            static fn (IpPrefixRecord $row): string => $row->id,
            $prefixes->items(),
        )));

        return Inertia::render('Admin/Network/Addressing', [
            'prefixes' => [
                'data' => array_map(
                    fn (IpPrefixRecord $prefix): array => $this->prefixRow($prefix, $counts),
                    $prefixes->items(),
                ),
                'currentPage' => $prefixes->currentPage(),
                'lastPage' => $prefixes->lastPage(),
                'total' => $prefixes->total(),
                'links' => $prefixes->linkCollection()->all(),
            ],
            'pools' => $this->poolRows(),
            'vlans' => Vlan::query()->orderBy('tag')->get()
                ->map(static fn (Vlan $vlan): array => [
                    'value' => $vlan->id,
                    'label' => $vlan->tag.' · '.$vlan->name,
                ])->all(),
            'filters' => [
                'pool' => $poolId === '' ? null : $poolId,
                'q' => $search === '' ? null : $search,
            ],
            'families' => array_map(
                static fn (IpFamily $family): array => [
                    'value' => $family->value,
                    'label' => (string) __($family->labelKey()),
                ],
                IpFamily::cases(),
            ),
            'purposes' => array_map(
                static fn (PoolPurpose $purpose): array => [
                    'value' => $purpose->value,
                    'label' => (string) __($purpose->labelKey()),
                ],
                PoolPurpose::cases(),
            ),
            'can' => ['manage' => $this->actor->can('network.ipam.manage')],
        ]);
    }

    public function show(IpPrefixRecord $prefix): Response
    {
        $this->authorizeFor('network.ipam.view');

        $prefix->load(['pool', 'vlan', 'parent']);

        $addresses = $prefix->addresses()
            ->with('assignment')
            ->orderBy('address_bytes')
            ->paginate(50)
            ->withQueryString();

        return Inertia::render('Admin/Network/Prefix', [
            'prefix' => [
                ...$this->prefixRow($prefix, $this->utilisation->counts([$prefix->id])),
                'gateway' => $prefix->gateway,
                'note' => $prefix->note,
                'parent' => $prefix->parent?->cidr,
                'children' => $prefix->children()->orderBy('network_bytes')->get()
                    ->map(static fn (IpPrefixRecord $child): array => [
                        'id' => $child->id,
                        'cidr' => $child->cidr,
                    ])->all(),
            ],
            'addresses' => [
                'data' => array_map($this->addressRow(...), $addresses->items()),
                'currentPage' => $addresses->currentPage(),
                'lastPage' => $addresses->lastPage(),
                'total' => $addresses->total(),
                'links' => $addresses->linkCollection()->all(),
            ],
            'states' => array_map(
                static fn (AddressState $state): array => [
                    'value' => $state->value,
                    'label' => (string) __($state->labelKey()),
                ],
                AddressState::cases(),
            ),
            'can' => ['manage' => $this->actor->can('network.ipam.manage')],
        ]);
    }

    public function storePool(PoolRequest $request): RedirectResponse
    {
        $this->authorizeFor('network.ipam.manage');

        $data = $request->validated();

        IpPool::query()->create([
            'organization_id' => $this->actor->organizationId(),
            'name' => $data['name'],
            'family' => $data['family'],
            'purpose' => $data['purpose'],
            'note' => $data['note'] ?? null,
        ]);

        return back()->with('status', __('network.pools.created'));
    }

    public function storePrefix(PrefixRequest $request): RedirectResponse
    {
        $this->authorizeFor('network.ipam.manage');

        $data = $request->validated();

        $pool = IpPool::query()->whereKey($data['ip_pool_id'])->firstOrFail();

        $this->prefixes->create(
            $pool,
            IpPrefix::parse($data['cidr']),
            $data['gateway'] ?? null,
            $data['site'] ?? null,
            $data['vlan_id'] ?? null,
            $data['note'] ?? null,
        );

        return back()->with('status', __('network.prefixes.created'));
    }

    public function destroyPrefix(IpPrefixRecord $prefix): RedirectResponse
    {
        $this->authorizeFor('network.ipam.manage');

        $this->prefixes->delete($prefix);

        return redirect('/admin/network/addressing')
            ->with('status', __('network.prefixes.deleted'));
    }

    /**
     * Take the next free address out of the prefix and hold it.
     *
     * Allocating and reserving are one action here on purpose: an operator
     * pressing this wants an address to give somebody, and one that came back
     * `available` would be one the next allocation could hand to a different
     * customer between the two clicks.
     */
    public function allocate(IpPrefixRecord $prefix): RedirectResponse
    {
        $this->authorizeFor('network.ipam.manage');

        $address = $this->allocator->handle($prefix);

        $address->forceFill(['state' => AddressState::Reserved->value])->save();

        return back()->with('status', __('network.addresses.allocated', ['address' => $address->address]));
    }

    public function releaseAddress(Request $request, IpAddressRecord $address): RedirectResponse
    {
        $this->authorizeFor('network.ipam.manage');

        $this->assignments->release(
            $address,
            quarantine: $request->boolean('quarantine', true),
            note: $request->string('note')->toString() ?: null,
        );

        return back()->with('status', __('network.addresses.released'));
    }

    /**
     * @param  array<string, array{used: int, reserved: int, quarantined: int}>  $counts
     * @return array<string, mixed>
     */
    private function prefixRow(IpPrefixRecord $prefix, array $counts): array
    {
        $count = $counts[$prefix->id] ?? ['used' => 0, 'reserved' => 0, 'quarantined' => 0];
        $capacity = $this->utilisation->capacity($prefix);

        return [
            'id' => $prefix->id,
            'cidr' => $prefix->cidr,
            'family' => $prefix->family->value,
            'familyLabel' => (string) __($prefix->family->labelKey()),
            'pool' => $prefix->pool?->name,
            'poolId' => $prefix->ip_pool_id,
            'purpose' => $prefix->pool?->purpose->value,
            'purposeLabel' => $prefix->pool === null
                ? null
                : (string) __($prefix->pool->purpose->labelKey()),
            'vlan' => $prefix->vlan === null ? null : $prefix->vlan->tag.' · '.$prefix->vlan->name,
            'site' => $prefix->site,
            'used' => $count['used'],
            'reserved' => $count['reserved'],
            // Null rather than a percentage for anything IPv6-sized: a bar
            // reading 0.0000000001% tells an operator nothing while looking
            // like it does.
            'capacity' => $capacity,
            'utilisation' => $capacity === null || $capacity === 0
                ? null
                : round($count['used'] / $capacity, 4),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function addressRow(IpAddressRecord $address): array
    {
        $assignment = $address->assignment;

        return [
            'id' => $address->id,
            'address' => $address->address,
            // Two fields, as everything that crosses to the browser is: the
            // value for the page to reason about and the label to print.
            'state' => $address->state->value,
            'stateLabel' => (string) __($address->state->labelKey()),
            'reverseDns' => $address->reverse_dns,
            'note' => $address->note,
            'holder' => $assignment instanceof IpAssignment ? $assignment->holder_label : null,
            'heldSince' => $assignment instanceof IpAssignment
                ? $assignment->assigned_at->toIso8601String()
                : null,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function poolRows(): array
    {
        return array_values(IpPool::query()->orderBy('name')->get()
            ->map(static fn (IpPool $pool): array => [
                'id' => $pool->id,
                'name' => $pool->name,
                'family' => $pool->family->value,
                'familyLabel' => (string) __($pool->family->labelKey()),
                'purpose' => $pool->purpose->value,
                'purposeLabel' => (string) __($pool->purpose->labelKey()),
            ])
            ->all());
    }

    private function authorizeFor(string $permission): void
    {
        if (! $this->actor->can($permission)) {
            throw new ForbiddenException(__('infrastructure.errors.not_permitted'));
        }
    }
}
