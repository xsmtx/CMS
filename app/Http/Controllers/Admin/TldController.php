<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Application\Domains\SaveTld;
use App\Application\Domains\TldCatalog;
use App\Domain\Catalog\CatalogStatus;
use App\Domain\Domains\Contracts\DomainRegistrar;
use App\Domain\Domains\DomainAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Domains\TldRequest;
use App\Infrastructure\Domains\Models\Tld;
use App\Infrastructure\Domains\Models\TldPrice;
use App\Infrastructure\Domains\RegistrarRegistry;
use App\Infrastructure\Shared\Models\CurrencyRecord;
use App\Support\Audit\Facades\Audit;
use App\Support\Errors\ForbiddenException;
use App\Support\Identity\CurrentActor;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * What each extension costs.
 *
 * The matrix is entered by hand, like every other price in this platform
 * ([ADR 0019](../../../../docs/adr/0019-price-matrix.md)). A `.com`
 * transfer costs a year's renewal and a redemption costs forty times a
 * registration; an operator who could only type one number would have to
 * choose which of those to be wrong about.
 */
final class TldController extends Controller
{
    public function __construct(
        private readonly CurrentActor $actor,
        private readonly RegistrarRegistry $registrars,
    ) {}

    public function index(): Response
    {
        $this->authorizeView();

        return Inertia::render('Admin/Tlds/Index', [
            'tlds' => Tld::query()
                ->with('prices')
                ->withCount('domains')
                ->orderBy('position')
                ->orderBy('extension')
                ->get()
                ->map(fn (Tld $tld): array => [
                    'id' => $tld->id,
                    'extension' => $tld->extension,
                    'registrar' => $tld->registrar,
                    'minYears' => $tld->min_years,
                    'maxYears' => $tld->max_years,
                    'allowsTransfer' => $tld->allows_transfer,
                    'allowsWhoisPrivacy' => $tld->allows_whois_privacy,
                    'requiresEppCode' => $tld->requires_epp_code,
                    'supportsIdn' => $tld->supports_idn,
                    'status' => $tld->status->value,
                    'position' => $tld->position,
                    'graceDays' => $tld->grace_days,
                    'redemptionDays' => $tld->redemption_days,
                    'domains' => $tld->domains_count,
                    'prices' => $tld->prices
                        ->map(fn (TldPrice $price): array => [
                            'action' => $price->action->value,
                            'years' => $price->years,
                            'currencyCode' => $price->currency_code,
                            'amountMinor' => $price->amount->minorUnits,
                            'costMinor' => $price->cost?->minorUnits,
                        ])
                        ->values()
                        ->all(),
                ])
                ->values()
                ->all(),
            'options' => [
                'registrars' => array_map(
                    static fn (DomainRegistrar $registrar): array => [
                        'value' => $registrar->key(),
                        'label' => $registrar->key(),
                    ],
                    $this->registrars->all(),
                ),
                'actions' => array_values(array_map(
                    static fn (DomainAction $action): array => [
                        'value' => $action->value,
                        'label' => (string) __($action->labelKey()),
                    ],
                    DomainAction::cases(),
                )),
                'statuses' => array_values(array_map(
                    static fn (CatalogStatus $status): array => [
                        'value' => $status->value,
                        'label' => (string) __($status->labelKey()),
                    ],
                    CatalogStatus::cases(),
                )),
                'currencies' => CurrencyRecord::query()
                    ->where('is_active', true)
                    ->orderBy('code')
                    ->get()
                    ->map(static fn (CurrencyRecord $currency): array => [
                        'code' => $currency->code,
                        'label' => $currency->code,
                        // A price grid cannot convert between minor units
                        // and a decimal without knowing how many places the
                        // currency has. JPY has none; TND has three.
                        'exponent' => $currency->exponent,
                    ])
                    ->values()
                    ->all(),
            ],
            'can' => ['manage' => $this->actor->can('catalog.tlds.manage')],
        ]);
    }

    public function store(TldRequest $request, SaveTld $save): RedirectResponse
    {
        $this->authorizeManage();

        $save->handle(null, $this->attributes($request), $this->prices($request), $this->actor->model());

        return back()->with('status', __('domains.tlds.saved'));
    }

    public function update(TldRequest $request, Tld $tld, SaveTld $save): RedirectResponse
    {
        $this->authorizeManage();

        $save->handle($tld, $this->attributes($request), $this->prices($request), $this->actor->model());

        return back()->with('status', __('domains.tlds.saved'));
    }

    public function destroy(Tld $tld, TldCatalog $catalog): RedirectResponse
    {
        $this->authorizeManage();

        if ($tld->domains()->exists()) {
            // Removing it would orphan names the platform is still
            // responsible for renewing.
            return back()->with('error', __('domains.tlds.in_use'));
        }

        $tld->delete();
        $catalog->forget();

        Audit::action('domains.tld.deleted')->by($this->actor->model())->on($tld)->write();

        return back()->with('status', __('domains.tlds.deleted'));
    }

    /**
     * @return array<string, mixed>
     */
    private function attributes(TldRequest $request): array
    {
        return [
            'extension' => $request->string('extension')->toString(),
            'registrar' => $request->input('registrar'),
            'min_years' => $request->integer('min_years'),
            'max_years' => $request->integer('max_years'),
            'allows_transfer' => $request->boolean('allows_transfer'),
            'allows_whois_privacy' => $request->boolean('allows_whois_privacy'),
            'requires_epp_code' => $request->boolean('requires_epp_code'),
            'supports_idn' => $request->boolean('supports_idn'),
            'status' => $request->string('status')->toString(),
            'position' => $request->integer('position'),
            'grace_days' => $request->integer('grace_days'),
            'redemption_days' => $request->integer('redemption_days'),
        ];
    }

    /**
     * @return list<array{action: string, years: int, currency_code: string, amount_minor: int, cost_minor: int|null}>
     */
    private function prices(TldRequest $request): array
    {
        /** @var list<array<string, mixed>> $rows */
        $rows = $request->input('prices', []);

        return array_values(array_map(
            static fn (array $row): array => [
                'action' => (string) $row['action'],
                'years' => (int) $row['years'],
                'currency_code' => (string) $row['currency_code'],
                'amount_minor' => (int) $row['amount_minor'],
                'cost_minor' => isset($row['cost_minor']) ? (int) $row['cost_minor'] : null,
            ],
            $rows,
        ));
    }

    private function authorizeView(): void
    {
        if (! $this->actor->can('catalog.tlds.view')) {
            throw new ForbiddenException(__('domains.domains.not_permitted'));
        }
    }

    private function authorizeManage(): void
    {
        if (! $this->actor->can('catalog.tlds.manage')) {
            throw new ForbiddenException(__('domains.domains.not_permitted'));
        }
    }
}
