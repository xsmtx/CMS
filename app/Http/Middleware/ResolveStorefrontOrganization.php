<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Branding\Surface;
use App\Domain\Organizations\OrganizationType;
use App\Infrastructure\Organizations\Models\Organization;
use App\Support\Branding\ActiveTheme;
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
 * Nothing here reads the request. A boundary that could be chosen by a query
 * parameter would not be a boundary — and neither would a theme.
 */
final readonly class ResolveStorefrontOrganization
{
    public function __construct(
        private OrganizationContext $context,
        private ActiveTheme $theme,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        // An authenticated staff member browsing the storefront keeps their
        // own boundary: they see the catalog they administer.
        if ($this->context->id() === null) {
            $providerId = Organization::query()
                ->withoutGlobalScope('organization')
                ->where('type', OrganizationType::Provider->value)
                ->value('id');

            if (is_string($providerId)) {
                $this->context->set($providerId);
            }
        }

        $this->theme->register(Surface::Storefront);

        return $next($request);
    }
}
