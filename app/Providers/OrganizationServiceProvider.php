<?php

declare(strict_types=1);

namespace App\Providers;

use App\Infrastructure\Organizations\OrganizationBoundary;
use App\Support\Organizations\OrganizationContext;
use Illuminate\Support\ServiceProvider;

/**
 * Registers the organization ownership boundary.
 *
 * Both services are singletons: the context must be one value per request or
 * job, and the boundary memoises organization paths for the duration of that
 * unit of work.
 */
final class OrganizationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(OrganizationContext::class);

        $this->app->singleton(
            OrganizationBoundary::class,
            fn (): OrganizationBoundary => new OrganizationBoundary(
                $this->app->make(OrganizationContext::class),
            ),
        );
    }
}
