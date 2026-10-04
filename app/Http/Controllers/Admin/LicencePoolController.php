<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Application\Vendors\LicenceCoverage;
use App\Domain\Provisioning\Contracts\ProvisioningModule;
use App\Http\Controllers\Controller;
use App\Http\Requests\Vendors\LicenceAllocationRequest;
use App\Http\Requests\Vendors\LicencePoolRequest;
use App\Infrastructure\Provisioning\Models\Server;
use App\Infrastructure\Provisioning\ModuleRegistry;
use App\Infrastructure\Vendors\Models\Contract;
use App\Infrastructure\Vendors\Models\LicenceAllocation;
use App\Infrastructure\Vendors\Models\LicencePool;
use App\Infrastructure\Vendors\Models\Vendor;
use App\Support\Audit\Facades\Audit;
use App\Support\Errors\ForbiddenException;
use App\Support\Identity\CurrentActor;
use App\Support\Organizations\OrganizationContext;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Operational licences, and which machine is using one (§24).
 *
 * **The screen opens on the difference**, the way backup coverage does. A
 * list of licences somebody already has a receipt for is a spreadsheet; the
 * answer nobody else can give is which seats are paid for and idle, which are
 * attached to a machine that has gone, and which machines are running
 * unlicensed.
 *
 * Permissions are `vendors.*` rather than a pair of their own. A licence pool
 * is a thing bought from a vendor under a contract, and a third permission
 * for the same family is a permission nobody assigns.
 *
 * **`LicencePoolController`, not `LicenceController`** — that name is taken,
 * by the screen for *this installation's own* licence (ADR 0041). Two very
 * different nouns wear the word, and the one that already exists keeps it.
 */
final class LicencePoolController extends Controller
{
    public function __construct(
        private readonly CurrentActor $actor,
        private readonly OrganizationContext $organizations,
        private readonly LicenceCoverage $coverage,
        private readonly ModuleRegistry $modules,
    ) {}

    public function index(): Response
    {
        $this->refuseUnless('vendors.view');

        return Inertia::render('Admin/Vendors/Licences', [
            'pools' => array_values($this->coverage->pools()
                ->map(static fn (LicencePool $pool): array => [
                    'id' => $pool->id,
                    'name' => $pool->name,
                    'vendor' => $pool->vendor->name ?? '',
                    'contract' => $pool->contract->title ?? null,
                    'forModule' => $pool->for_module,
                    'seats' => $pool->seats,
                    'used' => $pool->allocations_count ?? 0,
                    'spare' => $pool->spare(),
                    'unitPrice' => $pool->unitPrice()->format(app()->getLocale()),
                    'unitAmountMinor' => $pool->unit_amount_minor,
                    'currency' => $pool->currency_code,
                    'total' => $pool->total()->format(app()->getLocale()),
                    /*
                     * Which machines already hold a seat of this pool, so the
                     * allocation select can leave them out. A choice that is
                     * offered and then refused is a refusal the form could
                     * have avoided asking for.
                     */
                    'allocated' => array_values($pool->allocations
                        ->pluck('server_id')
                        ->filter()
                        ->all()),
                    'note' => $pool->note,
                ])
                ->all()),

            'orphaned' => array_values($this->coverage->orphaned()
                ->map(static fn (LicenceAllocation $row): array => [
                    'id' => $row->id,
                    'pool' => $row->pool->name ?? '',
                    'vendor' => $row->pool->vendor->name ?? '',
                    'server' => $row->server_name,
                    // The two reasons a seat is wasted read differently and
                    // must not be flattened: one machine left the fleet and
                    // somebody switched the other off.
                    'reason' => $row->server_id === null ? 'gone' : 'offline',
                    'reference' => $row->reference,
                ])
                ->all()),

            'missing' => array_map(
                static fn (array $gap): array => [
                    'pool' => $gap['pool']->name,
                    'poolId' => $gap['pool']->id,
                    'module' => $gap['pool']->for_module,
                    'servers' => array_values($gap['servers']
                        ->map(static fn (Server $server): array => [
                            'id' => $server->id,
                            'name' => $server->name,
                            'hostname' => $server->hostname,
                        ])
                        ->all()),
                ],
                $this->coverage->missing(),
            ),

            'summary' => $this->coverage->summary(),

            'options' => [
                'vendors' => array_values(Vendor::query()
                    ->orderBy('name')
                    ->get()
                    ->map(static fn (Vendor $vendor): array => [
                        'value' => $vendor->id,
                        'label' => $vendor->name,
                    ])
                    ->all()),
                'contracts' => array_values(Contract::query()
                    ->with('vendor')
                    ->orderBy('title')
                    ->get()
                    ->map(static fn (Contract $contract): array => [
                        'value' => $contract->id,
                        'label' => ($contract->vendor->name ?? '').' — '.$contract->title,
                    ])
                    ->all()),
                /*
                 * The modules this installation actually has. The list a
                 * control is drawn from and the list a write is validated
                 * against must be the same list, or a choice appears in a
                 * select and is then refused.
                 */
                'modules' => array_values(array_map(
                    static fn (ProvisioningModule $module): array => [
                        'value' => $module->key(),
                        'label' => $module->key(),
                    ],
                    $this->modules->all(),
                )),
                'servers' => array_values(Server::query()
                    ->orderBy('name')
                    ->get()
                    ->map(static fn (Server $server): array => [
                        'value' => $server->id,
                        'label' => $server->name.' ('.$server->module.')',
                    ])
                    ->all()),
            ],

            'can' => ['manage' => $this->actor->can('vendors.manage')],
        ]);
    }

    public function store(LicencePoolRequest $request): RedirectResponse
    {
        $this->refuseUnless('vendors.manage');

        $pool = new LicencePool;

        $pool->organization_id = (string) $this->organizations->id();
        $pool->fill($request->validated());
        $pool->save();

        Audit::action('vendors.licence.created')->by($this->actor->model())->on($pool)->write();

        return back()->with('status', __('vendors.saved'));
    }

    public function update(LicencePoolRequest $request, LicencePool $pool): RedirectResponse
    {
        $this->refuseUnless('vendors.manage');

        $pool->fill($request->validated());

        Audit::action('vendors.licence.updated')
            ->by($this->actor->model())
            ->on($pool)
            ->changedFrom($pool)
            ->write();

        $pool->save();

        return back()->with('status', __('vendors.saved'));
    }

    public function destroy(LicencePool $pool): RedirectResponse
    {
        $this->refuseUnless('vendors.manage');

        if ($pool->allocations()->exists()) {
            /*
             * Refused rather than cascaded, like a vendor with contracts. The
             * allocations are the record of which machines are running this,
             * and deleting a pool is not a request to forget that.
             */
            return back()->withErrors(['pool' => __('vendors.errors.has_allocations')]);
        }

        Audit::action('vendors.licence.deleted')->by($this->actor->model())->on($pool)->write();

        $pool->delete();

        return back()->with('status', __('vendors.deleted'));
    }

    public function allocate(LicenceAllocationRequest $request, LicencePool $pool): RedirectResponse
    {
        $this->refuseUnless('vendors.manage');

        $server = Server::query()->findOrFail($request->string('server_id')->value());

        if ($pool->allocations()->where('server_id', $server->id)->exists()) {
            // The unique index would refuse it as a 500. Said in words
            // instead, because "that machine already holds one" is an answer
            // rather than a failure.
            return back()->withErrors(['server_id' => __('vendors.errors.already_allocated')]);
        }

        $allocation = new LicenceAllocation;

        $allocation->organization_id = $pool->organization_id;
        $allocation->licence_pool_id = $pool->id;
        $allocation->server_id = $server->id;
        // Copied now, because it is the only thing left to read once the
        // server row has gone.
        $allocation->server_name = $server->name;
        $allocation->reference = $request->validated('reference');
        $allocation->note = $request->validated('note');
        $allocation->save();

        Audit::action('vendors.licence.allocated')
            ->by($this->actor->model())
            ->on($allocation)
            ->withMetadata(['pool' => $pool->name])
            ->write();

        return back()->with('status', __('vendors.saved'));
    }

    public function release(LicenceAllocation $allocation): RedirectResponse
    {
        $this->refuseUnless('vendors.manage');

        Audit::action('vendors.licence.released')
            ->by($this->actor->model())
            ->on($allocation)
            ->withMetadata(['pool' => $allocation->pool->name ?? ''])
            ->write();

        $allocation->delete();

        return back()->with('status', __('vendors.deleted'));
    }

    private function refuseUnless(string $permission): void
    {
        if (! $this->actor->can($permission)) {
            throw new ForbiddenException((string) __('vendors.errors.not_permitted'));
        }
    }
}
