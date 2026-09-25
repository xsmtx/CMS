<?php

declare(strict_types=1);

namespace App\Support\Branding;

use App\Application\Content\VisibleContent;
use App\Application\Domains\TldCatalog;
use App\Domain\Branding\Surface;
use App\Domain\Licensing\Contracts\Entitlements;
use App\Domain\Licensing\Feature;
use Illuminate\View\View;

/**
 * Puts the brand and the theme's settings on every storefront template.
 *
 * A composer rather than fourteen controllers each passing
 * `'brand' => config('app.name')`, which is what this replaced. Fourteen
 * copies of one decision is fourteen places to forget it, and the
 * fourteenth was always going to be the reseller's page.
 *
 * `$brand` stays a **string** so that every existing template keeps
 * working; `$branding` is the value object beside it for anything that
 * needs a logo, a colour or a legal link. Renaming the variable would have
 * meant touching every template in every theme anybody has written, to buy
 * nothing.
 */
final readonly class StorefrontComposer
{
    public function __construct(
        private CurrentBrand $brands,
        private ActiveTheme $theme,
        private Entitlements $entitlements,
        private TldCatalog $tlds,
        private VisibleContent $content,
    ) {}

    public function compose(View $view): void
    {
        $brand = $this->brands->forStorefront();

        $view->with([
            'brand' => $brand->name,
            'branding' => $brand,
            'theme' => $this->theme->settingsFor(Surface::Storefront),
            // Gated **here**, at render, not only where it is saved. An
            // installation whose licence lapses must show the mark again on
            // the next page load; enforcing it only on save would leave it
            // hidden forever on the strength of a choice made while the
            // entitlement held.
            'vendorMark' => $this->hidesVendorMark($brand->hideVendorMark)
                ? null
                : (string) config('platform.branding.vendor_mark'),
            'vendorUrl' => (string) config('platform.branding.vendor_url'),
            'sections' => $this->sections(),
        ]);
    }

    /**
     * Which public sections have anything in them.
     *
     * The header links only what exists, the way the admin rail does: a
     * Domains link on an installation with no TLDs on sale is a link to a
     * page that says "no extensions are on sale yet", and a visitor who
     * follows it learns that the navigation lies.
     *
     * Memoised per request — the layout and the page are both composed, and
     * this is three existence checks either way.
     *
     * @return array<string, bool>
     */
    private function sections(): array
    {
        return once(fn (): array => [
            'domains' => $this->tlds->sellable()->isNotEmpty(),
            'help' => $this->content->articles(signedIn: false, limit: 1)->isNotEmpty(),
            'announcements' => $this->content->announcements(signedIn: false, limit: 1)->isNotEmpty(),
        ]);
    }

    private function hidesVendorMark(bool $chosen): bool
    {
        return $chosen && $this->entitlements->allows(Feature::RemoveVendorMark->value);
    }
}
