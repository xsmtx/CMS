<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Access\CorePermissions;
use App\Domain\Access\PermissionRegistry;
use App\Domain\Organizations\OrganizationType;
use App\Infrastructure\Access\PermissionCache;
use App\Infrastructure\Organizations\Models\Organization;
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
        $this->resolvePolicies();
        $this->defineAbilities();
        $this->grantSuperAdmins();
    }

    /**
     * Models live in per-context namespaces rather than App\Models, so
     * Laravel's convention-based policy discovery does not find them. The
     * rule here is the same one, expressed for this layout: the policy for
     * App\Infrastructure\Crm\Models\Customer is App\Policies\CustomerPolicy.
     */
    private function resolvePolicies(): void
    {
        Gate::guessPolicyNamesUsing(
            static fn (string $modelClass): string => 'App\\Policies\\'.class_basename($modelClass).'Policy',
        );
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

        $this->defineResellerAdministration();
    }

    /**
     * Who may administer resellers.
     *
     * **This one is not a permission, and it cannot be one.** A reseller's own
     * Administrator holds every staff permission there is, by design — the
     * seeder gives that role the whole staff scope so a permission added in a
     * later phase reaches it. So a permission called `resellers.manage` would
     * land on a reseller's own administrator and let them set their own
     * margins and write their own balance.
     *
     * The real question is about *which organization somebody belongs to*:
     * only an organization that may own resellers may administer them. That is
     * the same shape as `isSuperAdmin` — who you are rather than what you
     * hold — and it is asked here so every screen asks it the same way.
     *
     * `organizations.manage` is still required on top: being the provider does
     * not make every one of its staff a reseller manager.
     */
    private function defineResellerAdministration(): void
    {
        /** @var array<string, bool> $answered */
        $answered = [];

        Gate::define('resellers.administer', static function (Authorizable $subject) use (&$answered): bool {
            if (! method_exists($subject, 'hasPermissionTo')
                || ! (bool) $subject->hasPermissionTo('organizations.manage')) {
                return false;
            }

            if (! $subject instanceof Model) {
                return false;
            }

            $organizationId = $subject->getAttribute('organization_id');

            if (! is_string($organizationId)) {
                return false;
            }

            // Memoised per request: the gate is asked two or three times on a
            // reseller screen and the answer cannot change inside one.
            return $answered[$organizationId] ??= self::ownsResellers($organizationId);
        });
    }

    /**
     * Whether this organization is allowed to have resellers beneath it.
     *
     * Read inside the boundary on purpose: an organization is the root of its
     * own subtree, so everybody can read their own row and nobody can read
     * somebody else's.
     */
    private static function ownsResellers(string $organizationId): bool
    {
        $organization = Organization::query()->where('id', $organizationId)->first();

        return $organization instanceof Organization
            && in_array(
                OrganizationType::Reseller,
                $organization->type->permittedChildTypes(),
                strict: true,
            );
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
