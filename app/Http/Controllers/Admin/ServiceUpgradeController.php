<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Application\Provisioning\ApplyUpgrade;
use App\Application\Provisioning\Exceptions\UpgradeRefused;
use App\Application\Provisioning\PriceUpgrade;
use App\Application\Provisioning\RequestUpgrade;
use App\Domain\Catalog\BillingCycle;
use App\Domain\Provisioning\UpgradeState;
use App\Http\Controllers\Controller;
use App\Http\Requests\Provisioning\RequestUpgradeRequest;
use App\Infrastructure\Catalog\Models\Product;
use App\Infrastructure\Catalog\Models\ProductPrice;
use App\Infrastructure\Crm\Models\Customer;
use App\Infrastructure\Provisioning\Models\Service;
use App\Infrastructure\Provisioning\Models\ServiceUpgrade;
use App\Support\Audit\Facades\Audit;
use App\Support\Identity\CurrentActor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Moving a service between plans, and the queue of moves in flight.
 *
 * **The preview and the charge are the same arithmetic.** `PriceUpgrade` is
 * asked for both, which is the rule the tax screen's Try-it panel states from
 * the other side: a preview that agreed with a second implementation and
 * disagreed with the invoice would be worse than no preview.
 *
 * The preview is a **POST**, not a GET. It carries a product and a cycle and
 * nothing about a customer, so the privacy rule that moved the tax preview off
 * a GET does not apply — but a GET that computed money would put a figure in
 * the access log, and the form is already here.
 */
final class ServiceUpgradeController extends Controller
{
    public function __construct(
        private readonly CurrentActor $actor,
        private readonly PriceUpgrade $pricing,
        private readonly RequestUpgrade $requests,
        private readonly ApplyUpgrade $applications,
    ) {}

    /**
     * What is in flight, which is the screen an operator opens.
     */
    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Service::class);

        $showAll = $request->boolean('all');

        $upgrades = ServiceUpgrade::query()
            ->with(['service' => fn ($query) => $query->with(Customer::displayNameWith('customer'))])
            ->unless($showAll, static fn ($query) => $query->whereIn('state', array_values(array_map(
                static fn (UpgradeState $state): string => $state->value,
                array_filter(UpgradeState::cases(), static fn (UpgradeState $s): bool => $s->isOpen()),
            ))))
            ->latest()
            ->paginate(20)
            ->withQueryString();

        return Inertia::render('Admin/Services/Upgrades', [
            'upgrades' => [
                'data' => array_map($this->row(...), array_values($upgrades->items())),
                'links' => $upgrades->linkCollection()->toArray(),
                'currentPage' => $upgrades->currentPage(),
                'lastPage' => $upgrades->lastPage(),
                'total' => $upgrades->total(),
            ],
            'filters' => ['all' => $showAll],
            'can' => ['manage' => $this->actor->can('upgrade', Service::class)],
        ]);
    }

    /**
     * What a move would cost, before anybody is charged for it.
     */
    public function preview(Request $request, Service $service): RedirectResponse
    {
        $this->authorize('upgrade', $service);

        $validated = $request->validate([
            'product' => ['required', 'string', 'exists:products,id'],
            'cycle' => ['required', 'string'],
        ]);

        $target = Product::query()->with('prices')->whereKey($validated['product'])->firstOrFail();
        $cycle = BillingCycle::tryFrom($validated['cycle']);

        if ($cycle === null) {
            return back()->withErrors(['cycle' => __('provisioning.upgrades.errors.not_recurring', [
                'name' => $target->name,
            ])]);
        }

        try {
            $change = $this->pricing->handle($service, $target, $cycle);
        } catch (UpgradeRefused $refusal) {
            return back()->withErrors(['product' => $refusal->getMessage()])->withInput();
        }

        return back()->with('upgradePreview', [
            'product' => $target->id,
            'productName' => $target->name,
            'cycle' => $cycle->value,
            'cycleLabel' => (string) __($cycle->labelKey()),
            'credit' => $change->credit->format(app()->getLocale()),
            'charge' => $change->charge->format(app()->getLocale()),
            'difference' => $change->difference()->format(app()->getLocale()),
            'differenceMinor' => $change->difference()->minorUnits,
            'daysRemaining' => $change->daysRemaining,
            'termDays' => $change->termDays,
            'restartsTerm' => $change->restartsTerm,
            'isFree' => $change->isFree(),
            'isUpgrade' => $change->isUpgrade(),
        ]);
    }

    public function store(RequestUpgradeRequest $request, Service $service): RedirectResponse
    {
        $this->authorize('upgrade', $service);

        $data = $request->validated();

        $target = Product::query()->with('prices')->whereKey($data['product'])->firstOrFail();
        $cycle = BillingCycle::from($data['cycle']);

        try {
            $upgrade = $this->requests->handle(
                $service,
                $target,
                $cycle,
                $this->actor->model(),
                $data['note'] ?? null,
            );
        } catch (UpgradeRefused $refusal) {
            return back()->withErrors(['product' => $refusal->getMessage()])->withInput();
        }

        /*
         * A move that costs nothing is carried out at once: there is no
         * invoice to wait for, and leaving it `authorized` would be a row
         * somebody has to press a second button on for no reason.
         */
        if ($upgrade->state === UpgradeState::Authorized) {
            $this->applications->handle($upgrade, $this->actor->model());
        }

        return back()->with('status', __('provisioning.upgrades.title'));
    }

    /**
     * Carry out a move that is paid for and has not gone through.
     *
     * The provider was down when the payment arrived, or a first attempt
     * failed. A button rather than a retry loop, because a plan change that
     * retried itself every five minutes against a refusing provider would be
     * a provider's rate limit.
     */
    public function apply(ServiceUpgrade $upgrade): RedirectResponse
    {
        $this->authorize('upgrade', Service::class);

        $this->applications->handle($upgrade, $this->actor->model());

        return back()->with('status', __('provisioning.upgrades.title'));
    }

    public function destroy(ServiceUpgrade $upgrade): RedirectResponse
    {
        $this->authorize('upgrade', Service::class);

        if (! $upgrade->state->isWithdrawable()) {
            return back()->withErrors([
                'state' => __('provisioning.upgrades.errors.not_withdrawable'),
            ]);
        }

        $upgrade->state = UpgradeState::Cancelled;
        $upgrade->save();

        Audit::action('provisioning.upgrade.withdrawn')
            ->by($this->actor->model())
            ->on($upgrade)
            ->forOrganization($upgrade->organization_id)
            ->write();

        return back()->with('status', __('provisioning.upgrades.withdraw'));
    }

    /**
     * The plans a service could move to, with the cycles each is sold in.
     *
     * Only what is sold **in this service's currency**: there is no exchange
     * rate in this product, so a plan priced only in dollars is not a plan a
     * euro customer can move to, and offering it would be a choice that is
     * then refused.
     *
     * @return list<array<string, mixed>>
     */
    public static function targetsFor(Service $service): array
    {
        $products = Product::query()
            ->with('prices')
            ->where('status', 'active')
            ->orderBy('name')
            ->get();

        $targets = [];

        foreach ($products as $product) {
            $cycles = $product->prices
                ->filter(static fn (ProductPrice $price): bool => $price->currency_code === $service->currency_code
                    && $price->billing_cycle->isRecurring())
                ->map(static fn (ProductPrice $price): array => [
                    'value' => $price->billing_cycle->value,
                    'label' => (string) __($price->billing_cycle->labelKey()),
                    'price' => $price->recurring->format(app()->getLocale()),
                ])
                ->values()
                ->all();

            if ($cycles === []) {
                continue;
            }

            $targets[] = [
                'id' => $product->id,
                'name' => $product->name,
                'current' => $product->id === $service->product_id,
                'cycles' => $cycles,
            ];
        }

        return $targets;
    }

    /**
     * @return array<string, mixed>
     */
    private function row(ServiceUpgrade $upgrade): array
    {
        return [
            'id' => $upgrade->id,
            'serviceId' => $upgrade->service_id,
            'service' => $upgrade->service?->name,
            'customer' => $upgrade->service?->customer?->displayName(),
            'from' => $upgrade->from_product_name,
            'to' => $upgrade->to_product_name,
            // Two fields, as everywhere: the value decides the tone and the
            // label is the word.
            'status' => $upgrade->state->value,
            'statusLabel' => (string) __($upgrade->state->labelKey()),
            'tone' => $upgrade->state->tone(),
            'difference' => $upgrade->difference()->format(app()->getLocale()),
            'isDowngrade' => $upgrade->isDowngrade(),
            'result' => $upgrade->result,
            'canApply' => $upgrade->state === UpgradeState::Authorized
                || $upgrade->state === UpgradeState::Failed,
            'canWithdraw' => $upgrade->state->isWithdrawable(),
            'requestedAt' => $upgrade->created_at?->toIso8601String(),
        ];
    }
}
