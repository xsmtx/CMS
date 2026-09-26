<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Application\Catalog\StorefrontCatalog;
use App\Application\Resellers\ResolveSellingPrice;
use App\Domain\Catalog\BillingCycle;
use App\Domain\Shared\Money;
use App\Infrastructure\Catalog\Models\Addon;
use App\Infrastructure\Catalog\Models\Option;
use App\Infrastructure\Catalog\Models\OptionGroup;
use App\Infrastructure\Catalog\Models\Product;
use App\Infrastructure\Catalog\Models\ProductGroup;
use App\Support\Catalog\StorefrontCurrency;
use App\Support\View\StorefrontImagery;
use App\Support\View\StorefrontRenderer;
use Illuminate\Contracts\Support\Renderable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The public catalog.
 *
 * Rendered through the storefront renderer rather than Inertia, so a theme
 * can replace the templates and a search engine can read the result.
 *
 * Every amount reaching a template is already a formatted string. Templates
 * are the last place a currency should be reasoned about.
 */
final class StorefrontCatalogController extends Controller
{
    public function __construct(
        private readonly StorefrontCatalog $catalog,
        private readonly StorefrontCurrency $currency,
        private readonly StorefrontRenderer $renderer,
        private readonly ResolveSellingPrice $sellingPrices,
        private readonly StorefrontImagery $imagery,
    ) {}

    public function index(): Renderable
    {
        $currency = $this->currency->current();

        if ($currency === null) {
            return $this->renderer->render('catalog', [
                'groups' => [],
                'currency' => null,
                'currencies' => [],
            ]);
        }

        $groups = $this->catalog->groups($currency)
            ->map(fn (ProductGroup $group): array => [
                'name' => $group->name,
                'slug' => $group->slug,
                'description' => $group->description,
                'products' => $group->products
                    ->map(fn (Product $product): array => $this->card($product, $currency))
                    ->all(),
            ])
            ->all();

        return $this->renderer->render('catalog', [
            'groups' => $groups,
            'currency' => $currency,
            'currencies' => $this->currency->available(),
        ]);
    }

    public function show(string $slug): Renderable
    {
        $currency = $this->currency->current();
        $product = $currency === null ? null : $this->catalog->product($slug, $currency);

        if ($product === null || $currency === null) {
            throw new NotFoundHttpException;
        }

        return $this->renderer->render('product', [
            'currency' => $currency,
            'currencies' => $this->currency->available(),
            'product' => [
                ...$this->card($product, $currency),
                'description' => $product->description,
                'group' => $product->group?->name,
                'requiresDomain' => $product->requires_domain,
                'cycles' => $this->cycles($product, $currency),
                'optionGroups' => $product->optionGroups
                    ->map(fn (OptionGroup $group): array => [
                        'name' => $group->name,
                        'key' => $group->key,
                        'type' => $group->type->value,
                        'description' => $group->description,
                        'isRequired' => $group->is_required,
                        'options' => $group->options
                            ->map(fn (Option $option): array => [
                                'label' => $option->label,
                                'value' => $option->value,
                                'isDefault' => $option->is_default,
                                'delta' => $this->delta($option, $currency),
                            ])
                            ->all(),
                    ])
                    ->all(),
                'addons' => $product->addons
                    ->filter(fn (Addon $addon): bool => $addon->status->isListed())
                    ->map(fn (Addon $addon): array => [
                        'name' => $addon->name,
                        'description' => $addon->description,
                        'price' => $this->cheapestAddonPrice($addon, $currency),
                    ])
                    ->values()
                    ->all(),
            ],
        ]);
    }

    /**
     * The configure screen: cycle, options, addons and a domain.
     *
     * Priced for every cycle the product is sold on, so switching the cycle
     * does not need a round trip and the numbers a customer compares are
     * the ones they will be charged.
     */
    public function configure(string $slug): Renderable
    {
        $currency = $this->currency->current();
        $product = $currency === null ? null : $this->catalog->product($slug, $currency);

        if ($product === null || $currency === null) {
            throw new NotFoundHttpException;
        }

        if (! $product->isOrderable()) {
            throw new NotFoundHttpException;
        }

        return $this->renderer->render('configure', [
            'currency' => $currency,
            'currencies' => $this->currency->available(),
            'product' => [
                'id' => $product->id,
                'name' => $product->name,
                'slug' => $product->slug,
                'tagline' => $product->tagline,
                'requiresDomain' => $product->requires_domain,
                'cycles' => $this->cycles($product, $currency),
                'optionGroups' => $product->optionGroups
                    ->map(fn (OptionGroup $group): array => [
                        'id' => $group->id,
                        'name' => $group->name,
                        'type' => $group->type->value,
                        'description' => $group->description,
                        'isRequired' => $group->is_required,
                        'minQuantity' => $group->min_quantity,
                        'maxQuantity' => $group->max_quantity,
                        'options' => $group->options
                            ->map(fn (Option $option): array => [
                                'id' => $option->id,
                                'label' => $option->label,
                                'isDefault' => $option->is_default,
                                'delta' => $this->delta($option, $currency),
                            ])
                            ->all(),
                    ])
                    ->all(),
                'addons' => $product->addons
                    ->filter(fn (Addon $addon): bool => $addon->status->isOrderable())
                    ->map(fn (Addon $addon): array => [
                        'id' => $addon->id,
                        'name' => $addon->name,
                        'description' => $addon->description,
                        'price' => $this->cheapestAddonPrice($addon, $currency),
                    ])
                    ->values()
                    ->all(),
            ],
        ]);
    }

    /**
     * Remember a currency choice. A POST because it changes what the next
     * page says, and because a crawler following links should not be able to
     * change anyone's session.
     */
    public function chooseCurrency(Request $request): RedirectResponse
    {
        $code = $request->string('currency')->toString();

        $this->currency->choose($code);

        return back();
    }

    /**
     * @return array<string, mixed>
     */
    private function card(Product $product, string $currency): array
    {
        $starting = $this->catalog->startingPrice($product, $currency);

        return [
            'name' => $product->name,
            'slug' => $product->slug,
            'tagline' => $product->tagline,
            // Null until `public/storefront/products/<slug>.png` exists.
            'image' => $this->imagery->product($product->slug),
            'features' => $product->features ?? [],
            'soldOut' => $product->isSoldOut(),
            'startingPrice' => $starting === null ? null : $this->present($starting['money'], $starting['cycle']),
        ];
    }

    /**
     * Every cycle the product is sold on, priced.
     *
     * @return list<array<string, mixed>>
     */
    private function cycles(Product $product, string $currency): array
    {
        $rows = [];

        foreach ($product->availableCycles($currency) as $cycle) {
            // Through the resolver, so the page, the cart and the order
            // all say one number.
            $recurring = $this->sellingPrices->recurring($product, $cycle, $currency);

            if ($recurring === null) {
                continue;
            }

            $setup = $this->sellingPrices->setup($product, $cycle, $currency);

            $rows[] = [
                ...$this->present($recurring, $cycle),
                'setup' => $setup === null || $setup->isZero() ? null : $setup->format(app()->getLocale()),
            ];
        }

        return $rows;
    }

    /**
     * An option's price difference, as a signed string. Null when the choice
     * costs nothing either way, which is most of them.
     */
    private function delta(Option $option, string $currency): ?string
    {
        $money = $option->recurringFor(BillingCycle::Monthly, $currency)
            ?? $option->prices->first()?->getAttribute('recurring');

        if (! $money instanceof Money || $money->isZero()) {
            return null;
        }

        return ($money->isNegative() ? '' : '+').$money->format(app()->getLocale());
    }

    /**
     * The lowest recurring amount an addon is offered at, for the card.
     */
    private function cheapestAddonPrice(Addon $addon, string $currency): ?string
    {
        $best = null;

        foreach ($addon->prices as $price) {
            if ($price->currency_code !== $currency) {
                continue;
            }

            if ($best === null || $price->recurring->minorUnits < $best->minorUnits) {
                $best = $price->recurring;
            }
        }

        return $best?->format(app()->getLocale());
    }

    /**
     * @return array{amount: string, cycle: string, cycleLabel: string, suffix: string}
     */
    private function present(Money $money, BillingCycle $cycle): array
    {
        return [
            'amount' => $money->format(app()->getLocale()),
            'cycle' => $cycle->value,
            'cycleLabel' => (string) __('catalog.cycles.'.$cycle->value),
            'suffix' => (string) __('catalog.cycle_short.'.$cycle->value),
        ];
    }
}
