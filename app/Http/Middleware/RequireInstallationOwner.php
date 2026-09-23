<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\Errors\ForbiddenException;
use App\Support\Identity\CurrentActor;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Who you have to be, as middleware.
 *
 * Three areas are the installation owner's and nobody else's: Apps and
 * Integrations, the Licence screen and Import. All three are about *who somebody
 * is* rather than what they may do, and none of them can be a permission — an
 * Administrator holds every staff permission there is by design, and a reseller's
 * Administrator is an Administrator.
 *
 * It exists as middleware rather than only as a check in each controller for one
 * reason, and it is an ordering reason: **authorization has to run before the
 * recent-password challenge.** With the check inside the controller, `auth.recent`
 * ran first, so a staff member who may not touch the Licence screen was asked to
 * confirm their password and *then* refused. That is rude, and it is a small
 * oracle — it says "you got past authentication and this endpoint exists" to
 * somebody who should have learned nothing.
 *
 * The controllers keep their own check as well. That is not redundant: a route
 * added without this middleware would otherwise be an open one, and two cheap
 * checks are worth less than one forgotten.
 */
final readonly class RequireInstallationOwner
{
    public function __construct(private CurrentActor $actor) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->actor->isSuperAdmin()) {
            // 403 rather than 404: the route is in the router and a signed-in
            // staff member learns nothing from its existence. What they must not
            // learn is anything behind it.
            throw new ForbiddenException(__('platform.errors.owner_only'));
        }

        return $next($request);
    }
}
