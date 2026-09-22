<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Contracts\Auth\Access\Authorizable;
use Illuminate\Support\Facades\Gate;
use Laravel\Horizon\HorizonApplicationServiceProvider;

/**
 * Horizon is an operational surface that exposes job payloads, so access is
 * gated on an explicit permission rather than on merely being logged in.
 */
final class HorizonServiceProvider extends HorizonApplicationServiceProvider
{
    protected function gate(): void
    {
        Gate::define('viewHorizon', static function (?Authorizable $user): bool {
            if ($user === null || ! method_exists($user, 'hasPermissionTo')) {
                return false;
            }

            return (bool) $user->hasPermissionTo('platform.queue.view');
        });
    }
}
