<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Application\Catalog\StorefrontCatalog;
use App\Domain\Catalog\BillingCycle;
use App\Domain\Shared\Money;
use App\Infrastructure\Catalog\Models\Addon;
use App\Infrastructure\Catalog\Models\Option;
use App\Infrastructure\Catalog\Models\OptionGroup;
use App\Infrastructure\Catalog\Models\Product;
use App\Infrastructure\Catalog\Models\ProductGroup;
use App\Support\Catalog\StorefrontCurrency;
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
    ) {}

    public function index(): Renderable
    {
        $currency = $this->currency->current();

        if ($currency === null) {
            return $this->renderer->render('catalog', [
                'brand' => config('app.name'),
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
            'brand' => config('app.name'),
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
            'brand' => config('app.name'),
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
            $recurring = $product->recurringFor($cycle, $currency);

            if ($recurring === null) {
                continue;
            }

            $setup = $product->setupFor($cycle, $currency);

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
