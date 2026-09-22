<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth\Concerns;

use App\Domain\Identity\Guard;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Reads the guard a route belongs to.
 *
 * Admin and client sign-in are the same flow against different guards, so the
 * controllers are shared. Every route in an authenticated area is registered
 * under that area's route-name prefix, so the name already says which guard
 * it serves and no extra wiring is needed in the route files.
 */
trait ResolvesGuard
{
    protected function guard(Request $request): Guard
    {
        $guard = Guard::fromRouteName($request->route()?->getName());

        if ($guard === null) {
            throw new RuntimeException(sprintf(
                'Route [%s] is not registered under an area name prefix, so its guard cannot be resolved.',
                $request->route()?->getName() ?? $request->path(),
            ));
        }

        return $guard;
    }
}
