<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Application\Organizations\CreateReseller;
use App\Application\Organizations\Exceptions\ResellerRefused;
use App\Application\Organizations\ResellerAttributes;
use App\Application\Resellers\RecordResellerEntry;
use App\Application\Resellers\ResellerLedger;
use App\Domain\Catalog\BillingCycle;
use App\Domain\Organizations\OrganizationType;
use App\Domain\Resellers\ResellerLedgerKind;
use App\Domain\Shared\Money;
use App\Http\Controllers\Controller;
use App\Infrastructure\Catalog\Models\Product;
use App\Infrastructure\Identity\Models\StaffUser;
use App\Infrastructure\Organizations\Models\Organization;
use App\Infrastructure\Resellers\Models\ResellerLedgerEntry;
use App\Infrastructure\Resellers\Models\ResellerPrice;
use App\Infrastructure\Resellers\Models\ResellerProduct;
use App\Support\Identity\CurrentActor;
use App\Support\Organizations\OrganizationContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The provider's resellers: who they are, what they may sell, and what they
 * owe.
 *
 * Every action here is authorized with **`resellers.administer`**, which is
 * not a permission — see `AccessServiceProvider`. A reseller's own
 * Administrator holds every staff permission by design, so a permission for
 * this would let a reseller set their own margins and write their own
 * balance. The gate asks which organization somebody belongs to instead.
 *
 * The reseller is resolved by hand rather than by route-model binding,
 * because "an organization inside my boundary" is not the same question as
 * "a reseller I administer": a customer is also inside the boundary, and a
 * customer with a margin page would be nonsense. A row that is not a
 * reseller answers 404, not 403 — the same rule the client area follows,
 * because a 403 confirms the record exists.
 */
final class ResellerController extends Controller
{
    public function __construct(
        private readonly CurrentActor $actor,
        private readonly ResellerLedger $ledger,
        private readonly OrganizationContext $organizations,
    ) {}

    public function index(): Response
    {
        $this->authorize('resellers.administer');

        $resellers = Organization::query()
            ->where('type', OrganizationType::Reseller->value)
            ->withCount('children')
            ->orderBy('name')
            ->get();

        return Inertia::render('Admin/Resellers/Index', [
            'resellers' => array_values($resellers
                ->map(fn (Organization $reseller): array => [
                    'id' => $reseller->id,
                    'name' => $reseller->name,
                    'slug' => $reseller->slug,
                    'isActive' => $reseller->is_active,
                    'customers' => (int) $reseller->getAttribute('children_count'),
                    'products' => $this->availableCount($reseller->id),
                    // Grouped by currency and never summed: adding lira to
                    // euros is the mistake this platform refuses everywhere.
                    'balances' => array_map(
                        static fn (Money $money): array => [
                            'currency' => $money->currency->code,
                            'amount' => $money->format(app()->getLocale()),
                            'minor' => $money->minorUnits,
                        ],
                        $this->ledger->balances($reseller->id),
                    ),
                    'createdAt' => $reseller->created_at->toIso8601String(),
                ])
                ->all()),
        ]);
    }

    public function create(): Response
    {
        $this->authorize('resellers.administer');

        return Inertia::render('Admin/Resellers/Create');
    }

    public function store(Request $request, CreateReseller $create): RedirectResponse
    {
        $this->authorize('resellers.administer');

        $data = $request->validate([
            'name' => ['required', 'string', 'max:191'],
            'slug' => ['nullable', 'string', 'max:191'],
            'owner_name' => ['required', 'string', 'max:191'],
            /*
             * Unique across the whole installation. The `unique` rule reads
             * the table rather than the model, so it sees past the
             * organization boundary — which is exactly right here: the
             * address belongs to a person, and a person works for one
             * organization.
             *
             * `CreateReseller` checks it again and refuses. That is not
             * duplication: this rule gives the operator a field error, and
             * the use case protects the two callers that are not this form.
             */
            'owner_email' => ['required', 'email', 'max:191', 'unique:staff_users,email'],
            'locale' => ['nullable', 'string', 'max:12'],
            'timezone' => ['nullable', 'string', 'max:64'],
        ]);

        try {
            $created = $create->handle(
                new ResellerAttributes(
                    name: $data['name'],
                    ownerName: $data['owner_name'],
                    ownerEmail: $data['owner_email'],
                    slug: $data['slug'] ?? null,
                    locale: $data['locale'] ?? config('app.locale'),
                    timezone: $data['timezone'] ?? config('app.timezone'),
                ),
                $this->actor->model(),
            );
        } catch (ResellerRefused $refused) {
            // The address was taken between the rule and the insert, or the
            // installation has no provider or no administrator role. All
            // three are things the operator can read and act on, so they
            // belong on the form rather than on an error page.
            throw ValidationException::withMessages(['owner_email' => $refused->getMessage()]);
        }

        return to_route('admin.resellers.show', $created['organization']->id)
            ->with('status', __('organizations.resellers.created', [
                'name' => $created['organization']->name,
            ]));
    }

    public function show(string $reseller): Response
    {
        $this->authorize('resellers.administer');

        $organization = $this->reseller($reseller);

        return Inertia::render('Admin/Resellers/Show', [
            'reseller' => [
                'id' => $organization->id,
                'name' => $organization->name,
                'slug' => $organization->slug,
                'isActive' => $organization->is_active,
                'customers' => $organization->children()->count(),
                'createdAt' => $organization->created_at->toIso8601String(),
                'staff' => $this->staff($organization->id),
            ],
            'catalogue' => $this->catalogue($organization->id),
            'prices' => $this->prices($organization->id),
            'cycles' => array_map(
                static fn (BillingCycle $cycle): array => [
                    'value' => $cycle->value,
                    'label' => (string) __($cycle->labelKey()),
                ],
                BillingCycle::cases(),
            ),
            'balances' => array_map(
                static fn (Money $money): array => [
                    'currency' => $money->currency->code,
                    'amount' => $money->format(app()->getLocale()),
                    'minor' => $money->minorUnits,
                ],
                $this->ledger->balances($organization->id),
            ),
            'statement' => array_map(
                static fn (ResellerLedgerEntry $row): array => [
                    'id' => $row->id,
                    'kind' => $row->kind->value,
                    'kindLabel' => (string) __($row->kind->labelKey()),
                    'increases' => $row->kind->increasesBalance(),
                    'amount' => $row->amount->format(app()->getLocale()),
                    'balance' => $row->balance->format(app()->getLocale()),
                    'currency' => (string) $row->currency_code,
                    'description' => $row->description,
                    'recordedBy' => $row->recorded_by,
                    'occurredAt' => $row->occurred_at->toIso8601String(),
                ],
                $this->ledger->statement($organization->id),
            ),
            'kinds' => array_map(
                static fn (ResellerLedgerKind $kind): array => [
                    'value' => $kind->value,
                    'label' => (string) __($kind->labelKey()),
                    'increases' => $kind->increasesBalance(),
                ],
                ResellerLedgerKind::cases(),
            ),
        ]);
    }

    /**
     * May this reseller sell this product, and with what markup.
     *
     * One endpoint for both, because they are one row. `is_enabled` false and
     * no row at all mean the same thing to the catalogue, and keeping the row
     * is what lets a provider turn a product off for a month without losing
     * the margin somebody agreed on the telephone.
     */
    public function availability(Request $request, string $reseller): RedirectResponse
    {
        $this->authorize('resellers.administer');

        $organization = $this->reseller($reseller);

        $data = $request->validate([
            'product_id' => ['required', 'string', 'ulid', 'exists:products,id'],
            'is_enabled' => ['required', 'boolean'],
            // A decimal string, never a float: a margin is arithmetic on
            // integer minor units and a float would round somebody's price.
            'margin_percent' => ['nullable', 'numeric', 'between:-99.9999,999.9999'],
        ]);

        $this->organizations->runAs($organization->id, function () use ($organization, $data): void {
            ResellerProduct::query()->updateOrCreate(
                ['organization_id' => $organization->id, 'product_id' => $data['product_id']],
                [
                    'is_enabled' => $data['is_enabled'],
                    // Null and '0.0000' are different answers: one is "the
                    // provider's price", the other is somebody having typed
                    // zero, and the screen shows which.
                    'margin_percent' => $data['margin_percent'] ?? null,
                ],
            );
        });

        return back()->with('status', __('organizations.resellers.availability_saved'));
    }

    /**
     * An exact number for one product, cycle and currency.
     *
     * It beats any margin, because an operator who typed a number meant that
     * number. Clearing it is deleting the row rather than writing zero —
     * absence means "not priced this way" here as everywhere, and zero means
     * free.
     */
    public function price(Request $request, string $reseller): RedirectResponse
    {
        $this->authorize('resellers.administer');

        $organization = $this->reseller($reseller);

        $data = $request->validate([
            'product_id' => ['required', 'string', 'ulid', 'exists:products,id'],
            'billing_cycle' => ['required', Rule::enum(BillingCycle::class)],
            'currency_code' => ['required', 'string', 'size:3'],
            'recurring_minor' => ['nullable', 'integer', 'min:0'],
            'setup_minor' => ['nullable', 'integer', 'min:0'],
        ]);

        $currency = strtoupper($data['currency_code']);

        $this->organizations->runAs($organization->id, function () use ($organization, $data, $currency): void {
            $keys = [
                'organization_id' => $organization->id,
                'product_id' => $data['product_id'],
                'billing_cycle' => $data['billing_cycle'],
                'currency_code' => $currency,
            ];

            if (($data['recurring_minor'] ?? null) === null) {
                ResellerPrice::query()->where($keys)->delete();

                return;
            }

            ResellerPrice::query()->updateOrCreate($keys, [
                'recurring_minor' => $data['recurring_minor'],
                'setup_minor' => $data['setup_minor'] ?? 0,
            ]);
        });

        return back()->with('status', __('organizations.resellers.price_saved'));
    }

    /**
     * One movement on the reseller's account.
     *
     * The amount is a positive integer in minor units and the kind decides
     * direction (ADR 0024). A form that offered a sign would be a form
     * somebody could use to record a payment that took money away.
     */
    public function ledger(Request $request, string $reseller): RedirectResponse
    {
        $this->authorize('resellers.administer');

        $organization = $this->reseller($reseller);

        $data = $request->validate([
            'kind' => ['required', Rule::enum(ResellerLedgerKind::class)],
            'currency_code' => ['required', 'string', 'size:3'],
            'amount_minor' => ['required', 'integer', 'min:1'],
            'description' => ['nullable', 'string', 'max:512'],
            'occurred_at' => ['nullable', 'date'],
        ]);

        $entry = $this->ledger->record(
            new RecordResellerEntry(
                organizationId: $organization->id,
                kind: ResellerLedgerKind::from($data['kind']),
                amount: Money::ofMinor($data['amount_minor'], strtoupper($data['currency_code'])),
                occurredAt: ($data['occurred_at'] ?? null) === null
                    ? null
                    : CarbonImmutable::parse($data['occurred_at']),
                description: $data['description'] ?? null,
                // The person, as words. A statement is read by people, and an
                // id in that column is a lookup nobody will do.
                recordedBy: $this->actor->model()?->getAttribute('name'),
            ),
            $this->actor->model(),
        );

        return back()->with('status', __('organizations.resellers.entry_recorded', [
            'balance' => $entry->balance->format(app()->getLocale()),
        ]));
    }

    /**
     * The reseller, or a 404.
     *
     * Resolved by hand because route-model binding would answer "an
     * organization inside my boundary", and a customer is inside it too. A
     * row that is not a reseller is not a record this screen has, so it is
     * missing rather than forbidden — a 403 would confirm it exists.
     */
    private function reseller(string $id): Organization
    {
        $organization = Organization::query()
            ->where('id', $id)
            ->where('type', OrganizationType::Reseller->value)
            ->first();

        if (! $organization instanceof Organization) {
            abort(404);
        }

        return $organization;
    }

    /**
     * How many products this reseller may actually sell.
     *
     * Counted inside the reseller's own boundary, because the rows belong to
     * them — which is also what stops one reseller's count being another's.
     */
    private function availableCount(string $organizationId): int
    {
        return $this->organizations->runAs(
            $organizationId,
            static fn (): int => ResellerProduct::query()->where('is_enabled', true)->count(),
        );
    }

    /**
     * Who runs this reseller.
     *
     * @return list<array<string, mixed>>
     */
    private function staff(string $organizationId): array
    {
        return $this->organizations->runAs(
            $organizationId,
            static fn (): array => array_values(StaffUser::query()
                ->orderBy('name')
                ->limit(10)
                ->get()
                ->map(static fn (StaffUser $user): array => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'status' => $user->status->value,
                    'statusLabel' => (string) __($user->status->labelKey()),
                ])
                ->all()),
        );
    }

    /**
     * Every product the provider sells, and what this reseller may do with it.
     *
     * The whole catalogue rather than only the allowed rows: the screen is
     * where somebody decides what to allow, and a list of what is already
     * allowed cannot be used to allow anything new.
     *
     * @return list<array<string, mixed>>
     */
    private function catalogue(string $organizationId): array
    {
        $availability = $this->organizations->runAs(
            $organizationId,
            static fn (): array => ResellerProduct::query()
                ->get()
                ->keyBy('product_id')
                ->all(),
        );

        $products = Product::query()
            ->with('group')
            ->orderBy('position')
            ->orderBy('name')
            ->get();

        return array_values($products
            ->map(static function (Product $product) use ($availability): array {
                $row = $availability[$product->id] ?? null;

                return [
                    'id' => $product->id,
                    'name' => $product->name,
                    'group' => $product->group?->name,
                    'isEnabled' => $row instanceof ResellerProduct && $row->is_enabled,
                    // A string, not a number: the margin is a decimal the
                    // browser must not turn into a float on the way through.
                    'marginPercent' => $row?->margin_percent === null
                        ? null
                        : (string) $row->margin_percent,
                ];
            })
            ->all());
    }

    /**
     * The exact prices this reseller has set.
     *
     * @return list<array<string, mixed>>
     */
    private function prices(string $organizationId): array
    {
        return $this->organizations->runAs(
            $organizationId,
            static fn (): array => array_values(ResellerPrice::query()
                ->with('product')
                ->get()
                ->map(static fn (ResellerPrice $price): array => [
                    'id' => $price->id,
                    'productId' => $price->product_id,
                    'product' => $price->product?->name,
                    'cycle' => $price->billing_cycle->value,
                    'cycleLabel' => (string) __($price->billing_cycle->labelKey()),
                    'currency' => (string) $price->currency_code,
                    'recurringMinor' => $price->recurring_minor,
                    'setupMinor' => $price->setup_minor,
                    'recurring' => $price->recurring->format(app()->getLocale()),
                    'setup' => $price->setup->format(app()->getLocale()),
                ])
                ->all()),
        );
    }
}
