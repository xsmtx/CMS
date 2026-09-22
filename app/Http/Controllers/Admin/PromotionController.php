<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Application\Promotions\DeletePromotion;
use App\Application\Promotions\PromotionAttributes;
use App\Application\Promotions\SavePromotion;
use App\Domain\Promotions\PromotionApplication;
use App\Domain\Promotions\PromotionScope;
use App\Domain\Promotions\PromotionType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Ordering\PromotionRequest;
use App\Infrastructure\Catalog\Models\Product;
use App\Infrastructure\Promotions\Models\Promotion;
use App\Support\Identity\CurrentActor;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

final class PromotionController extends Controller
{
    public function __construct(private readonly CurrentActor $actor) {}

    public function index(): Response
    {
        $this->authorize('viewAny', Promotion::class);

        return Inertia::render('Admin/Promotions/Index', [
            'promotions' => Promotion::query()
                ->withCount('redemptions')
                ->orderByDesc('is_active')
                ->orderBy('code')
                ->get()
                ->map(fn (Promotion $promotion): array => [
                    'id' => $promotion->id,
                    'code' => $promotion->code,
                    'name' => $promotion->name,
                    'type' => $promotion->type->value,
                    'value' => $promotion->type === PromotionType::Fixed
                        ? $promotion->amount?->format(app()->getLocale())
                        : $promotion->percentage.'%',
                    'scope' => (string) __($promotion->scope->labelKey()),
                    'isActive' => $promotion->is_active,
                    'usageLimit' => $promotion->usage_limit,
                    'usageCount' => $promotion->usage_count,
                    'redemptions' => $promotion->redemptions_count,
                    'endsAt' => $promotion->ends_at?->toIso8601String(),
                ])
                ->values()
                ->all(),
            'canManage' => $this->actor->can('create', Promotion::class),
        ]);
    }

    public function create(): Response
    {
        $this->authorize('create', Promotion::class);

        return Inertia::render('Admin/Promotions/Form', [
            'promotion' => null,
            ...$this->formOptions(),
        ]);
    }

    public function store(PromotionRequest $request, SavePromotion $save): RedirectResponse
    {
        $this->authorize('create', Promotion::class);

        $save->handle(
            (string) $this->actor->organizationId(),
            $this->attributes($request),
            null,
            $this->actor->model(),
        );

        return to_route('admin.promotions.index')->with('status', __('ordering.promotions.saved'));
    }

    public function edit(Promotion $promotion): Response
    {
        $this->authorize('view', $promotion);

        return Inertia::render('Admin/Promotions/Form', [
            'promotion' => [
                'id' => $promotion->id,
                'code' => $promotion->code,
                'name' => $promotion->name,
                'description' => $promotion->description,
                'type' => $promotion->type->value,
                'amountMinor' => $promotion->amount?->minorUnits,
                'currencyCode' => $promotion->currency_code,
                'percentage' => $promotion->percentage,
                'scope' => $promotion->scope->value,
                'application' => $promotion->application->value,
                'billingCycles' => $promotion->billing_cycles ?? [],
                'startsAt' => $promotion->starts_at?->toDateString(),
                'endsAt' => $promotion->ends_at?->toDateString(),
                'usageLimit' => $promotion->usage_limit,
                'usageCount' => $promotion->usage_count,
                'perCustomerLimit' => $promotion->per_customer_limit,
                'minimumSubtotalMinor' => $promotion->minimum_subtotal?->minorUnits,
                'newCustomersOnly' => $promotion->new_customers_only,
                'stackable' => $promotion->stackable,
                'isActive' => $promotion->is_active,
                'productIds' => $promotion->products()->pluck('products.id')->all(),
                'redemptions' => $promotion->redemptions()->count(),
            ],
            ...$this->formOptions(),
        ]);
    }

    public function update(PromotionRequest $request, Promotion $promotion, SavePromotion $save): RedirectResponse
    {
        $this->authorize('update', $promotion);

        $save->handle($promotion->organization_id, $this->attributes($request), $promotion, $this->actor->model());

        return to_route('admin.promotions.index')->with('status', __('ordering.promotions.saved'));
    }

    public function destroy(Promotion $promotion, DeletePromotion $delete): RedirectResponse
    {
        $this->authorize('delete', $promotion);

        $delete->handle($promotion, $this->actor->model());

        return to_route('admin.promotions.index')->with('status', __('ordering.promotions.deleted'));
    }

    /**
     * @return array<string, mixed>
     */
    private function formOptions(): array
    {
        return [
            'types' => $this->options(PromotionType::cases()),
            'scopes' => $this->options(PromotionScope::cases()),
            'applications' => $this->options(PromotionApplication::cases()),
            'cycles' => ProductController::cycles(),
            'currencies' => ProductController::currencies(),
            'products' => Product::query()
                ->orderBy('name')
                ->get()
                ->map(fn (Product $product): array => ['value' => $product->id, 'label' => $product->name])
                ->values()
                ->all(),
        ];
    }

    /**
     * @param  list<PromotionType|PromotionScope|PromotionApplication>  $cases
     * @return list<array{value: string, label: string}>
     */
    private function options(array $cases): array
    {
        return array_map(
            static fn (PromotionType|PromotionScope|PromotionApplication $case): array => [
                'value' => $case->value,
                'label' => (string) __($case->labelKey()),
            ],
            $cases,
        );
    }

    private function attributes(PromotionRequest $request): PromotionAttributes
    {
        /** @var list<string> $cycles */
        $cycles = $request->input('billing_cycles', []);

        /** @var list<string> $productIds */
        $productIds = $request->input('product_ids', []);

        return new PromotionAttributes(
            code: $request->string('code')->upper()->toString(),
            name: $request->string('name')->toString(),
            type: PromotionType::from($request->string('type')->toString()),
            scope: PromotionScope::from($request->string('scope')->toString()),
            application: PromotionApplication::from($request->string('application')->toString()),
            description: $request->input('description'),
            amountMinor: $request->input('amount_minor') === null ? null : (int) $request->input('amount_minor'),
            currencyCode: $request->input('currency_code'),
            percentage: $request->input('percentage'),
            // An empty list means "every cycle", which is what null says.
            billingCycles: $cycles === [] ? null : $cycles,
            startsAt: $this->date($request->input('starts_at')),
            endsAt: $this->date($request->input('ends_at')),
            usageLimit: $request->input('usage_limit') === null ? null : (int) $request->input('usage_limit'),
            perCustomerLimit: $request->input('per_customer_limit') === null
                ? null
                : (int) $request->input('per_customer_limit'),
            minimumSubtotalMinor: $request->input('minimum_subtotal_minor') === null
                ? null
                : (int) $request->input('minimum_subtotal_minor'),
            newCustomersOnly: $request->boolean('new_customers_only'),
            stackable: $request->boolean('stackable'),
            isActive: $request->boolean('is_active'),
            productIds: $productIds,
        );
    }

    private function date(mixed $value): ?CarbonImmutable
    {
        return is_string($value) && $value !== '' ? CarbonImmutable::parse($value) : null;
    }
}
