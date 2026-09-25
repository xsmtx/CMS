<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Application\Shared\ResolveSeller;
use App\Domain\Branding\Surface;
use App\Domain\Organizations\OrganizationType;
use App\Infrastructure\Organizations\Models\Organization;
use App\Support\Branding\ActiveTheme;
use App\Support\Identity\CurrentActor;
use App\Support\Organizations\OrganizationContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gives the public storefront an organization boundary.
 *
 * Without one, a visitor would see every reseller's catalog at once, since
 * an unbounded context means unfiltered reads. The storefront has no
 * authenticated actor to take a boundary from, so it is taken from the
 * installation instead: the provider organization at the root.
 *
 * Once the organization is known, the active theme's view paths are
 * registered — which is why this happens in middleware rather than at boot:
 * the theme depends on whose storefront this is, and that is not knowable
 * until the boundary exists.
 *
 * Resolving a reseller's own storefront from its hostname is Phase 13's
 * reseller work. Until then every public page serves the provider's
 * catalog, which is what a single-brand installation wants.
 *
 * **A signed-in customer is not a shop.** A customer organization sells
 * nothing, so keeping their own boundary here served them an empty catalog
 * under their own name — "Customer is installed and running" on the shop of
 * the company they buy from. A client session is therefore narrowed to the
 * organization that sells to them (`ResolveSeller`), which for a reseller's
 * customer is the reseller and for everybody else is the provider. Staff
 * keep their own, because a reseller's operator previewing their storefront
 * is the whole reason that exception exists.
 *
 * Nothing here reads the request. A boundary that could be chosen by a query
 * parameter would not be a boundary — and neither would a theme.
 */
final readonly class ResolveStorefrontOrganization
{
    public function __construct(
        private OrganizationContext $context,
        private ActiveTheme $theme,
        private CurrentActor $actor,
        private ResolveSeller $sellers,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $boundary = $this->storefrontBoundary();

        if ($boundary !== null) {
            $this->context->set($boundary);
        }

        $this->theme->register(Surface::Storefront);

        return $next($request);
    }

    /**
     * Whose shop this is: the seller, or the installation.
     *
     * Null means leave the boundary alone, which is the staff case — an
     * operator sees the catalog they administer.
     */
    private function storefrontBoundary(): ?string
    {
        $current = $this->context->id();

        if ($current === null) {
            return $this->provider();
        }

        if ($this->actor->isStaff()) {
            return null;
        }

        $seller = $this->sellers->forOrganization($current);

        return $seller === $current ? $this->provider() : $seller;
    }

    private function provider(): ?string
    {
        $providerId = Organization::query()
            ->withoutGlobalScope('organization')
            ->where('type', OrganizationType::Provider->value)
            ->value('id');

        return is_string($providerId) ? $providerId : null;
    }
}
