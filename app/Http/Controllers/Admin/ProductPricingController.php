<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Application\Catalog\SavePriceMatrix;
use App\Http\Controllers\Controller;
use App\Http\Requests\Catalog\PriceMatrixRequest;
use App\Infrastructure\Catalog\Models\Product;
use App\Infrastructure\Catalog\Models\ProductPrice;
use App\Support\Identity\CurrentActor;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The price matrix for one product.
 *
 * Separate from the product controller because it answers to a different
 * permission: an operator may be trusted to write a product description
 * without being trusted to change what it costs.
 */
final class ProductPricingController extends Controller
{
    public function __construct(private readonly CurrentActor $actor) {}

    public function edit(Product $product): Response
    {
        $this->authorize('price', $product);

        $product->load('prices');

        return Inertia::render('Admin/Catalog/Products/Pricing', [
            'product' => [
                'id' => $product->id,
                'name' => $product->name,
                'status' => $product->status->value,
            ],
            'prices' => $product->prices
                ->map(fn (ProductPrice $price): array => [
                    'billingCycle' => $price->billing_cycle->value,
                    'currencyCode' => $price->currency_code,
                    'recurringMinor' => $price->recurring->minorUnits,
                    'setupMinor' => $price->setup->minorUnits,
                ])
                ->values()
                ->all(),
            'cycles' => ProductController::cycles(),
            'currencies' => ProductController::currencies(),
        ]);
    }

    public function update(PriceMatrixRequest $request, Product $product, SavePriceMatrix $save): RedirectResponse
    {
        $this->authorize('price', $product);

        $entries = $request->entries();

        // A product price is what a customer pays; a negative one would be
        // a credit dressed up as a plan.
        foreach ($entries as $entry) {
            if ($entry->recurring->isNegative() || $entry->setup->isNegative()) {
                return back()->withErrors(['prices' => __('catalog.pricing.negative_not_allowed')]);
            }
        }

        $save->handle($product, $entries, $this->actor->model());

        return to_route('admin.catalog.products.pricing.edit', $product)
            ->with('status', __('catalog.pricing.saved'));
    }
}
