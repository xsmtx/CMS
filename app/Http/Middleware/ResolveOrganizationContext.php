<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\Identity\CurrentActor;
use App\Support\Organizations\OrganizationContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Establishes the organization boundary for the request from the
 * authenticated actor, whichever guard they signed in on.
 *
 * The boundary is never taken from user input. No header, query parameter or
 * request body can widen it. Impersonation changes the actor, and therefore
 * the boundary, through the same single path.
 */
final readonly class ResolveOrganizationContext
{
    public function __construct(
        private OrganizationContext $context,
        private CurrentActor $actor,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $organizationId = $this->actor->organizationId();

        if ($organizationId !== null) {
            $this->context->set($organizationId);
        }

        return $next($request);
    }
}
