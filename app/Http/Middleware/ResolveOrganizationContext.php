<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\Organizations\OrganizationContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Establishes the organization boundary for the request from the
 * authenticated actor.
 *
 * The boundary is never taken from user input — no header, query parameter
 * or request body can widen it. Impersonation (Phase 1) changes the actor,
 * and therefore the boundary, through the same single path.
 */
final readonly class ResolveOrganizationContext
{
    public function __construct(private OrganizationContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user !== null) {
            $organizationId = $user->getAttribute('organization_id');

            if (is_string($organizationId)) {
                $this->context->set($organizationId);
            }
        }

        return $next($request);
    }
}
