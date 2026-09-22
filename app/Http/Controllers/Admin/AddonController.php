<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Application\Catalog\AddonAttributes;
use App\Application\Catalog\DeleteAddon;
use App\Application\Catalog\SaveAddon;
use App\Application\Catalog\SavePriceMatrix;
use App\Domain\Catalog\CatalogStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Catalog\AddonRequest;
use App\Http\Requests\Catalog\PriceMatrixRequest;
use App\Infrastructure\Catalog\Models\Addon;
use App\Infrastructure\Catalog\Models\AddonPrice;
use App\Infrastructure\Catalog\Models\Product;
use App\Support\Identity\CurrentActor;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

final class AddonController extends Controller
{
    public function __construct(private readonly CurrentActor $actor) {}

    public function index(Product $product): Response
    {
        $this->authorize('view', $product);

        $product->load('addons.prices');

        return Inertia::render('Admin/Catalog/Addons/Index', [
            'product' => ['id' => $product->id, 'name' => $product->name],
            'addons' => $product->addons
                ->map(fn (Addon $addon): array => [
                    'id' => $addon->id,
                    'name' => $addon->name,
                    'slug' => $addon->slug,
                    'status' => $addon->status->value,
                    'position' => $addon->position,
                    'priceCount' => $addon->prices->count(),
                ])
                ->values()
                ->all(),
            'statuses' => ProductGroupController::statuses(),
            'canManage' => $this->actor->can('price', $product),
        ]);
    }

    public function create(Product $product): Response
    {
        $this->authorize('price', $product);

        return Inertia::render('Admin/Catalog/Addons/Form', [
            'product' => ['id' => $product->id, 'name' => $product->name],
            'addon' => null,
            'statuses' => ProductGroupController::statuses(),
            'cycles' => ProductController::cycles(),
            'currencies' => ProductController::currencies(),
        ]);
    }

    public function store(AddonRequest $request, Product $product, SaveAddon $save): RedirectResponse
    {
        $this->authorize('price', $product);

        $addon = $save->handle($product, $this->attributes($request), null, $this->actor->model());

        return to_route('admin.catalog.products.addons.edit', [$product, $addon])
            ->with('status', __('catalog.addons.saved'));
    }

    public function edit(Product $product, Addon $addon): Response
    {
        $this->authorize('view', $product);

        $addon->load('prices');

        return Inertia::render('Admin/Catalog/Addons/Form', [
            'product' => ['id' => $product->id, 'name' => $product->name],
            'addon' => [
                'id' => $addon->id,
                'name' => $addon->name,
                'slug' => $addon->slug,
                'description' => $addon->description,
                'status' => $addon->status->value,
                'position' => $addon->position,
                'prices' => $addon->prices
                    ->map(fn (AddonPrice $price): array => [
                        'billingCycle' => $price->billing_cycle->value,
                        'currencyCode' => $price->currency_code,
                        'recurringMinor' => $price->recurring->minorUnits,
                        'setupMinor' => $price->setup->minorUnits,
                    ])
                    ->values()
                    ->all(),
            ],
            'statuses' => ProductGroupController::statuses(),
            'cycles' => ProductController::cycles(),
            'currencies' => ProductController::currencies(),
        ]);
    }

    public function update(AddonRequest $request, Product $product, Addon $addon, SaveAddon $save): RedirectResponse
    {
        $this->authorize('price', $product);

        $save->handle($product, $this->attributes($request), $addon, $this->actor->model());

        return to_route('admin.catalog.products.addons.edit', [$product, $addon])
            ->with('status', __('catalog.addons.saved'));
    }

    /**
     * The addon's own price matrix, saved on its own so an operator can
     * change what it costs without touching its description.
     */
    public function pricing(
        PriceMatrixRequest $request,
        Product $product,
        Addon $addon,
        SavePriceMatrix $save,
    ): RedirectResponse {
        $this->authorize('price', $product);

        $save->handle($addon, $request->entries(), $this->actor->model());

        return to_route('admin.catalog.products.addons.edit', [$product, $addon])
            ->with('status', __('catalog.pricing.saved'));
    }

    public function destroy(Product $product, Addon $addon, DeleteAddon $delete): RedirectResponse
    {
        $this->authorize('price', $product);

        $delete->handle($addon, $this->actor->model());

        return to_route('admin.catalog.products.addons.index', $product)
            ->with('status', __('catalog.addons.deleted'));
    }

    private function attributes(AddonRequest $request): AddonAttributes
    {
        return new AddonAttributes(
            name: $request->string('name')->toString(),
            slug: $request->input('slug'),
            description: $request->input('description'),
            status: CatalogStatus::from($request->string('status')->toString()),
            position: (int) $request->input('position', 0),
        );
    }
}
