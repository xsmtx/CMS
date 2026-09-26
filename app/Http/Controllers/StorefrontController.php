<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Catalog\CatalogStatus;
use App\Infrastructure\Catalog\Models\Product;
use App\Support\Catalog\StorefrontCurrency;
use App\Support\View\StorefrontImagery;
use App\Support\View\StorefrontRenderer;
use Illuminate\Contracts\Support\Renderable;

/**
 * Public storefront entry point, rendered through the renderer abstraction
 * rather than a hard dependency on Blade or Inertia.
 *
 * The home page and the catalog stay separate pages: this one is the brand's
 * front door, /store is the list of what is for sale. All this page needs to
 * know is whether there is anything to point at yet.
 */
final class StorefrontController extends Controller
{
    public function __invoke(
        StorefrontRenderer $renderer,
        StorefrontCurrency $currency,
        StorefrontImagery $imagery,
    ): Renderable {
        $code = $currency->current();

        return $renderer->render('home', [
            'currency' => $code,
            'currencies' => $currency->available(),
            'hasCatalog' => $code !== null && $this->hasSomethingToSell($code),
            // Null until somebody puts a file there, and the tile composes
            // either way.
            'heroImage' => $imagery->hero(),
        ]);
    }

    private function hasSomethingToSell(string $currency): bool
    {
        return Product::query()
            ->where('status', CatalogStatus::Active->value)
            ->whereHas('prices', fn ($prices) => $prices->where('currency_code', $currency))
            ->exists();
    }
}
