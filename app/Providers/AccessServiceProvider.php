<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Access\CorePermissions;
use App\Domain\Access\PermissionRegistry;
use App\Infrastructure\Access\PermissionCache;
use App\Support\Audit\Facades\Audit;
use Illuminate\Contracts\Auth\Access\Authorizable;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

/**
 * Authorization wiring.
 *
 * Every declared permission becomes a Gate ability, so `can('settings.manage')`
 * works in controllers, policies, Blade and Vue without per-feature glue.
 */
final class AccessServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(
            PermissionRegistry::class,
            static fn (): PermissionRegistry => new PermissionRegistry(CorePermissions::all()),
        );

        $this->app->singleton(PermissionCache::class, fn (): PermissionCache => new PermissionCache(
            cache: $this->app->make(CacheRepository::class),
            prefix: (string) config('platform.access.cache_prefix', 'access:permissions:'),
            ttl: (int) config('platform.access.cache_ttl', 900),
        ));
    }

    public function boot(): void
    {
        $this->defineAbilities();
        $this->grantSuperAdmins();
    }

    private function defineAbilities(): void
    {
        $registry = $this->app->make(PermissionRegistry::class);

        foreach ($registry->slugs() as $slug) {
            Gate::define($slug, static function (Authorizable $subject) use ($slug): bool {
                if (! method_exists($subject, 'hasPermissionTo')) {
                    return false;
                }

                return (bool) $subject->hasPermissionTo($slug);
            });
        }
    }

    /**
     * The one bypass in the system.
     *
     * It is deliberately narrow — a single named role — and a bypass of a
     * high-risk capability is audited once per request, so that "a super
     * admin did it" never becomes an invisible explanation during an incident
     * review. Low-risk read checks are not audited: a single admin page
     * render performs dozens of them and the noise would bury the signal.
     */
    private function grantSuperAdmins(): void
    {
        $registry = $this->app->make(PermissionRegistry::class);

        /** @var array<string, true> $recorded */
        $recorded = [];

        Gate::before(function (Authorizable $subject, string $ability) use ($registry, &$recorded): ?bool {
            if (! method_exists($subject, 'isSuperAdmin') || ! $subject->isSuperAdmin()) {
                return null;
            }

            $isHighRisk = $registry->has($ability) && $registry->get($ability)->highRisk;

            if ($isHighRisk && ! isset($recorded[$ability])) {
                $recorded[$ability] = true;

                Audit::action('access.superadmin.bypass')
                    ->by($subject instanceof Model ? $subject : null)
                    ->withMetadata(['ability' => $ability])
                    ->write();
            }

            return true;
        });
    }
}
