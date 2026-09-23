<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Application\Health\MaintenanceMode;
use App\Support\Errors\MaintenanceException;
use App\Support\Identity\CurrentActor;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Closes the storefront and the client area, and leaves staff a way in.
 *
 * Not `php artisan down`, which takes the admin panel down with everything
 * else — including the screen an operator needs in order to turn it back
 * on. The bypass is the whole difference: the person fixing the thing
 * being maintained has to be able to see it.
 *
 * The admin area is never behind this middleware, so a staff member who is
 * not signed in can still reach the login page. That is deliberate: a
 * maintenance switch that locks out the only people who can unset it is a
 * switch nobody will dare use.
 */
final readonly class EnforceMaintenanceMode
{
    public function __construct(
        private MaintenanceMode $maintenance,
        private CurrentActor $actor,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->maintenance->isActive()) {
            return $next($request);
        }

        if ($this->actor->isStaff()) {
            return $next($request);
        }

        throw new MaintenanceException(
            $this->maintenance->message() ?? (string) __('automation.maintenance.default_message'),
        );
    }
}
