<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Vendors\ContractTerm;
use App\Domain\Vendors\VendorKind;
use App\Http\Controllers\Controller;
use App\Http\Requests\Vendors\ContractRequest;
use App\Http\Requests\Vendors\VendorRequest;
use App\Infrastructure\Vendors\Models\Contract;
use App\Infrastructure\Vendors\Models\Vendor;
use App\Support\Audit\Facades\Audit;
use App\Support\Errors\ForbiddenException;
use App\Support\Identity\CurrentActor;
use App\Support\Organizations\OrganizationContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Who this business buys from, and what it agreed (§24).
 *
 * Rows an operator types: no API says what a transit contract costs or when
 * the licences renew, which puts this in the same family as the DCIM spine
 * and cost entries.
 *
 * **Contracts first, soonest decision at the top**, because that is the one
 * question this screen is opened for. A list of vendors alphabetically is an
 * address book; a list of what has to be decided this month is the reason the
 * feature exists.
 */
final class VendorController extends Controller
{
    public function __construct(
        private readonly CurrentActor $actor,
        private readonly OrganizationContext $organizations,
    ) {}

    public function index(): Response
    {
        $this->refuseUnless('vendors.view');

        $now = CarbonImmutable::now();

        return Inertia::render('Admin/Vendors/Index', [
            'vendors' => array_values(Vendor::query()
                ->withCount('contracts')
                ->orderBy('name')
                ->get()
                ->map(static fn (Vendor $vendor): array => [
                    'id' => $vendor->id,
                    'name' => $vendor->name,
                    // Two fields, as everywhere: the value and the word.
                    'kind' => $vendor->kind->value,
                    'kindLabel' => (string) __($vendor->kind->labelKey()),
                    'contactName' => $vendor->contact_name,
                    'contactEmail' => $vendor->contact_email,
                    'contactPhone' => $vendor->contact_phone,
                    'reference' => $vendor->account_reference,
                    'contracts' => $vendor->contracts_count,
                ])
                ->all()),

            /*
             * Ordered by when somebody has to decide, not by when a contract
             * ends: on an auto-renewing one those are different dates, and
             * the second is the one that has already passed.
             */
            'contracts' => array_values(Contract::query()
                ->with('vendor')
                ->orderByRaw('ends_on is null')
                ->oldest('ends_on')
                ->limit(500)
                ->get()
                ->map(fn (Contract $contract): array => $this->row($contract, $now))
                ->all()),

            'kinds' => array_map(
                static fn (VendorKind $kind): array => [
                    'value' => $kind->value,
                    'label' => (string) __($kind->labelKey()),
                ],
                VendorKind::cases(),
            ),
            'terms' => array_map(
                static fn (ContractTerm $term): array => [
                    'value' => $term->value,
                    'label' => (string) __($term->labelKey()),
                ],
                ContractTerm::cases(),
            ),
            'can' => ['manage' => $this->actor->can('vendors.manage')],
        ]);
    }

    public function store(VendorRequest $request): RedirectResponse
    {
        $this->refuseUnless('vendors.manage');

        $vendor = new Vendor;

        $vendor->organization_id = (string) $this->organizations->id();
        $vendor->fill($request->validated());
        $vendor->save();

        Audit::action('vendors.vendor.created')
            ->by($this->actor->model())
            ->on($vendor)
            ->write();

        return back()->with('status', __('vendors.saved'));
    }

    public function update(VendorRequest $request, Vendor $vendor): RedirectResponse
    {
        $this->refuseUnless('vendors.manage');

        $vendor->fill($request->validated());

        Audit::action('vendors.vendor.updated')
            ->by($this->actor->model())
            ->on($vendor)
            ->changedFrom($vendor)
            ->write();

        $vendor->save();

        return back()->with('status', __('vendors.saved'));
    }

    public function destroy(Vendor $vendor): RedirectResponse
    {
        $this->refuseUnless('vendors.manage');

        if ($vendor->contracts()->exists()) {
            /*
             * Refused rather than cascaded. The migration cascades because a
             * database has to answer *something* when an organization goes,
             * but an operator pressing Delete on a vendor has not asked to
             * lose four contracts and the renewal dates in them.
             */
            return back()->withErrors(['vendor' => __('vendors.errors.has_contracts')]);
        }

        Audit::action('vendors.vendor.deleted')->by($this->actor->model())->on($vendor)->write();

        $vendor->delete();

        return back()->with('status', __('vendors.deleted'));
    }

    public function storeContract(ContractRequest $request, Vendor $vendor): RedirectResponse
    {
        $this->refuseUnless('vendors.manage');

        $contract = new Contract;

        $contract->organization_id = $vendor->organization_id;
        $contract->vendor_id = $vendor->id;
        $contract->fill($request->validated());
        $contract->save();

        Audit::action('vendors.contract.created')
            ->by($this->actor->model())
            ->on($contract)
            ->withMetadata(['vendor' => $vendor->name])
            ->write();

        return back()->with('status', __('vendors.saved'));
    }

    public function updateContract(ContractRequest $request, Contract $contract): RedirectResponse
    {
        $this->refuseUnless('vendors.manage');

        $contract->fill($request->validated());

        Audit::action('vendors.contract.updated')
            ->by($this->actor->model())
            ->on($contract)
            ->changedFrom($contract)
            ->write();

        $contract->save();

        return back()->with('status', __('vendors.saved'));
    }

    public function destroyContract(Contract $contract): RedirectResponse
    {
        $this->refuseUnless('vendors.manage');

        Audit::action('vendors.contract.deleted')->by($this->actor->model())->on($contract)->write();

        $contract->delete();

        return back()->with('status', __('vendors.deleted'));
    }

    /**
     * @return array<string, mixed>
     */
    private function row(Contract $contract, CarbonImmutable $now): array
    {
        $days = $contract->daysRemaining($now);

        return [
            'id' => $contract->id,
            'vendorId' => $contract->vendor_id,
            'vendor' => $contract->vendor->name ?? '',
            'title' => $contract->title,
            'reference' => $contract->reference,
            'term' => $contract->term->value,
            'termLabel' => (string) __($contract->term->labelKey()),
            // Money is a formatted amount at the edge and integer minor units
            // everywhere else (non-negotiable 4).
            'amount' => $contract->price()->format(app()->getLocale()),
            'amountMinor' => $contract->amount_minor,
            'currency' => $contract->currency_code,
            'startsOn' => $contract->starts_on?->toDateString(),
            'endsOn' => $contract->ends_on?->toDateString(),
            'autoRenews' => $contract->auto_renews,
            'noticeDays' => $contract->notice_days,
            // The date that actually matters, which on an auto-renewing
            // contract is not the end.
            'decideBy' => $contract->decideBy()?->toDateString(),
            'daysRemaining' => $days,
            'note' => $contract->note,
        ];
    }

    private function refuseUnless(string $permission): void
    {
        if (! $this->actor->can($permission)) {
            throw new ForbiddenException((string) __('vendors.errors.not_permitted'));
        }
    }
}
