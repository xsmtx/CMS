<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Application\Catalog\DeleteProduct;
use App\Application\Catalog\ProductAttributes;
use App\Application\Catalog\SaveProduct;
use App\Domain\Catalog\BillingCycle;
use App\Domain\Catalog\CatalogStatus;
use App\Domain\Catalog\ProductType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Catalog\ProductRequest;
use App\Infrastructure\Catalog\Models\Product;
use App\Infrastructure\Catalog\Models\ProductGroup;
use App\Infrastructure\Shared\Models\CurrencyRecord;
use App\Support\Identity\CurrentActor;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

final class ProductController extends Controller
{
    public function __construct(private readonly CurrentActor $actor) {}

    public function index(): Response
    {
        $this->authorize('viewAny', Product::class);

        return Inertia::render('Admin/Catalog/Products/Index', [
            'products' => Product::query()
                ->with('group')
                ->withCount('prices')
                ->orderBy('product_group_id')
                ->orderBy('position')
                ->orderBy('name')
                ->get()
                ->map(fn (Product $product): array => [
                    'id' => $product->id,
                    'name' => $product->name,
                    'slug' => $product->slug,
                    'type' => $product->type->value,
                    'typeLabel' => (string) __('catalog.types.'.$product->type->value),
                    'status' => $product->status->value,
                    'group' => $product->group?->name,
                    'groupId' => $product->product_group_id,
                    'stock' => $product->stock,
                    'priceCount' => $product->prices_count,
                ])
                ->values()
                ->all(),
            'statuses' => ProductGroupController::statuses(),
        ]);
    }

    public function create(): Response
    {
        $this->authorize('create', Product::class);

        return Inertia::render('Admin/Catalog/Products/Form', [
            'product' => null,
            ...$this->formOptions(),
        ]);
    }

    public function store(ProductRequest $request, SaveProduct $save): RedirectResponse
    {
        $this->authorize('create', Product::class);

        $product = $save->handle(
            (string) $this->actor->organizationId(),
            $this->attributes($request),
            null,
            $this->actor->model(),
        );

        // Straight on to pricing: a product with no price cannot be sold,
        // so leaving the operator on a list would hide the next step.
        return to_route('admin.catalog.products.pricing.edit', $product)
            ->with('status', __('catalog.products.saved'));
    }

    public function edit(Product $product): Response
    {
        $this->authorize('view', $product);

        $product->load(['optionGroups.options', 'addons']);

        return Inertia::render('Admin/Catalog/Products/Form', [
            'product' => [
                'id' => $product->id,
                'productGroupId' => $product->product_group_id,
                'name' => $product->name,
                'slug' => $product->slug,
                'type' => $product->type->value,
                'tagline' => $product->tagline,
                'description' => $product->description,
                'features' => $product->features ?? [],
                'status' => $product->status->value,
                'position' => $product->position,
                'stock' => $product->stock,
                'requiresDomain' => $product->requires_domain,
                'optionGroupCount' => $product->optionGroups->count(),
                'addonCount' => $product->addons->count(),
                'canPrice' => $this->actor->can('price', $product),
            ],
            ...$this->formOptions(),
        ]);
    }

    public function update(ProductRequest $request, Product $product, SaveProduct $save): RedirectResponse
    {
        $this->authorize('update', $product);

        $save->handle($product->organization_id, $this->attributes($request), $product, $this->actor->model());

        return to_route('admin.catalog.products.edit', $product)->with('status', __('catalog.products.saved'));
    }

    public function destroy(Product $product, DeleteProduct $delete): RedirectResponse
    {
        $this->authorize('delete', $product);

        $delete->handle($product, $this->actor->model());

        return to_route('admin.catalog.products.index')->with('status', __('catalog.products.deleted'));
    }

    /**
     * @return list<array{value: string, label: string, recurring: bool}>
     */
    public static function cycles(): array
    {
        $cycles = BillingCycle::cases();
        usort($cycles, fn (BillingCycle $a, BillingCycle $b): int => $a->sortOrder() <=> $b->sortOrder());

        return array_map(
            fn (BillingCycle $cycle): array => [
                'value' => $cycle->value,
                'label' => (string) __('catalog.cycles.'.$cycle->value),
                'recurring' => $cycle->isRecurring(),
            ],
            $cycles,
        );
    }

    /**
     * The currencies a price may be entered in, with the exponent the form
     * needs to turn what an operator types into minor units.
     *
     * @return list<array{code: string, symbol: string|null, exponent: int, isBase: bool}>
     */
    public static function currencies(): array
    {
        return array_values(CurrencyRecord::query()
            ->where('is_active', true)
            ->orderByDesc('is_base')
            ->orderBy('code')
            ->get()
            ->map(fn (CurrencyRecord $record): array => [
                'code' => $record->code,
                'symbol' => $record->symbol,
                'exponent' => $record->exponent,
                'isBase' => $record->is_base,
            ])
            ->all());
    }

    /**
     * Everything the product form needs besides the product itself.
     *
     * @return array<string, mixed>
     */
    private function formOptions(): array
    {
        return [
            'groups' => ProductGroup::query()
                ->orderBy('position')
                ->orderBy('name')
                ->get()
                ->map(fn (ProductGroup $group): array => [
                    'value' => $group->id,
                    'label' => $group->name,
                ])
                ->values()
                ->all(),
            'types' => array_map(
                fn (ProductType $type): array => [
                    'value' => $type->value,
                    'label' => (string) __('catalog.types.'.$type->value),
                    'requiresDomain' => $type->requiresDomain(),
                ],
                ProductType::cases(),
            ),
            'statuses' => ProductGroupController::statuses(),
            'cycles' => self::cycles(),
            'currencies' => self::currencies(),
        ];
    }

    private function attributes(ProductRequest $request): ProductAttributes
    {
        /** @var list<string|null> $features */
        $features = $request->input('features', []);

        return new ProductAttributes(
            productGroupId: $request->string('product_group_id')->toString(),
            name: $request->string('name')->toString(),
            type: ProductType::from($request->string('type')->toString()),
            slug: $request->input('slug'),
            tagline: $request->input('tagline'),
            description: $request->input('description'),
            features: array_values(array_filter(
                array_map(static fn (?string $feature): string => trim((string) $feature), $features),
                static fn (string $feature): bool => $feature !== '',
            )),
            status: CatalogStatus::from($request->string('status')->toString()),
            position: (int) $request->input('position', 0),
            stock: $request->input('stock') === null ? null : (int) $request->input('stock'),
            requiresDomain: $request->input('requires_domain') === null ? null : $request->boolean('requires_domain'),
        );
    }
}
