<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Application\Domains\AddDomainToCart;
use App\Application\Domains\CheckDomainAvailability;
use App\Application\Domains\DomainOffer;
use App\Application\Domains\Exceptions\DomainNotSellable;
use App\Application\Domains\TldCatalog;
use App\Application\Ordering\ResolveCart;
use App\Domain\Domains\Exceptions\InvalidDomainName;
use App\Infrastructure\Domains\Models\Tld;
use App\Support\Catalog\StorefrontCurrency;
use App\Support\View\StorefrontRenderer;
use Illuminate\Contracts\Support\Renderable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * The domain search.
 *
 * Answers with what the registry said, including "we could not ask". A
 * search box that quietly turns a timeout into "available" is the single
 * most expensive bug this feature can have: the customer pays, the
 * registration fails, and somebody has to explain it.
 */
final class StorefrontDomainController extends Controller
{
    public function __construct(
        private readonly TldCatalog $catalog,
        private readonly CheckDomainAvailability $availability,
        private readonly StorefrontCurrency $currency,
        private readonly StorefrontRenderer $renderer,
        private readonly ResolveCart $carts,
    ) {}

    public function index(Request $request): Renderable
    {
        $query = trim($request->string('q')->toString());
        $years = max($request->integer('years'), 0);
        $currency = $this->currency->current();

        $offers = [];
        $suggestions = [];
        $error = null;

        // No currency configured means no catalog at all; the page says so
        // rather than searching for a price that cannot exist.
        if ($query !== '' && $currency !== null) {
            try {
                $offers = $this->availability->handle($query, $currency, $years);

                $suggestions = $this->availability->suggestions(
                    $this->catalog->parse($query)->sld,
                    $currency,
                );
            } catch (InvalidDomainName $exception) {
                $error = $exception->getMessage();
            }
        }

        return $this->renderer->render('domain-search', [
            'currency' => $currency,
            'currencies' => $this->currency->available(),
            'query' => $query,
            'error' => $error,
            'offers' => array_map($this->present(...), $offers),
            'suggestions' => array_values(array_filter(
                array_map($this->present(...), $suggestions),
                // The exact match is already shown above it.
                static fn (array $row): bool => $row['available'],
            )),
            'extensions' => $currency === null
                ? []
                : $this->catalog->offeredIn($currency)
                    ->map(static fn (Tld $tld): string => '.'.$tld->extension)
                    ->values()
                    ->all(),
        ]);
    }

    public function store(Request $request, AddDomainToCart $add): RedirectResponse
    {
        $currency = $this->currency->current();

        if ($currency === null) {
            return back()->with('error', __('domains.search.none'));
        }

        $cart = $this->carts->forCurrency($currency);

        try {
            $add->handle(
                $cart,
                $request->string('domain')->toString(),
                max($request->integer('years'), 1),
            );
        } catch (DomainNotSellable|InvalidDomainName $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return to_route('storefront.cart');
    }

    /**
     * @return array<string, mixed>
     */
    private function present(DomainOffer $offer): array
    {
        $locale = app()->getLocale();

        return [
            'name' => (string) $offer->name,
            'tld' => $offer->name->tld,
            'years' => $offer->years,
            'price' => $offer->price?->format($locale),
            'transferPrice' => $offer->transferPrice?->format($locale),
            'sold' => $offer->sold,
            'available' => $offer->isOrderable(),
            'transferable' => $offer->isTransferable(),
            // Kept as its own state all the way to the page. A customer
            // told "we could not check" can try again; one told "taken"
            // goes somewhere else.
            'unknown' => $offer->isUnknown(),
            'premium' => $offer->availability !== null && $offer->availability->premium,
        ];
    }
}
