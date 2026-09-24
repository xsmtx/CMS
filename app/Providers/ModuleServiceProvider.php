<?php

declare(strict_types=1);

namespace App\Providers;

use App\Application\Marketplace\VerifyPackage;
use App\Domain\Access\PermissionRegistry;
use App\Domain\Marketplace\Contracts\MarketplaceClient;
use App\Infrastructure\Marketplace\HttpMarketplaceClient;
use App\Infrastructure\Marketplace\UnconfiguredMarketplaceClient;
use App\Infrastructure\Modules\ActiveModules;
use App\Infrastructure\Modules\ModuleCatalogue;
use App\Infrastructure\Modules\ModuleLoader;
use App\Support\Correlation\CorrelationContext;
use Composer\Autoload\ClassLoader;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\ServiceProvider;
use Throwable;

/**
 * Where modules are given to the platform, and the platform to modules.
 *
 * Everything here is bound lazily. Nothing is scanned, no class is loaded
 * and the database is not touched until something asks for a registry —
 * because this provider also runs during `artisan migrate` on an empty
 * database, and during `artisan config:cache` where there may be no
 * database at all.
 *
 * The composer autoloader is fetched from the one place it exists rather
 * than rebuilt: `vendor/autoload.php` returns the live `ClassLoader`, and a
 * second instance would resolve nothing the first one knows about.
 */
final class ModuleServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ModuleCatalogue::class, static fn (): ModuleCatalogue => new ModuleCatalogue(
            rtrim((string) config('platform.modules.path', base_path('modules')), '/\\'),
        ));

        $this->app->singleton(ModuleLoader::class, static fn (): ModuleLoader => new ModuleLoader(
            require base_path('vendor/autoload.php'),
            (string) config('platform.version', '1.0.0'),
        ));

        $this->app->singleton(ActiveModules::class, static fn ($app): ActiveModules => new ActiveModules(
            $app->make(ModuleCatalogue::class),
            $app->make(ModuleLoader::class),
        ));

        /*
         * The vendor's catalogue, or nothing at all.
         *
         * An installation with no marketplace URL gets the unconfigured client
         * rather than a null: an empty catalogue is an ordinary installation,
         * and a screen that has to check for null is a screen that will forget
         * to once.
         *
         * The licence key travels with the request because the catalogue is
         * what *this* installation may have. It is read here rather than held,
         * so rotating it does not need a restart.
         */
        $this->app->singleton(MarketplaceClient::class, static function ($app): MarketplaceClient {
            $url = config('platform.marketplace.api_url');

            if (! is_string($url) || trim($url) === '') {
                return new UnconfiguredMarketplaceClient;
            }

            $key = config('platform.licensing.key');

            return new HttpMarketplaceClient(
                baseUrl: trim($url),
                licenceKey: is_string($key) ? $key : null,
                correlation: $app->make(CorrelationContext::class),
                verifier: $app->make(VerifyPackage::class),
                timeout: (int) config('platform.marketplace.timeout', 15),
                retries: (int) config('platform.marketplace.retries', 2),
            );
        });
    }

    public function boot(): void
    {
        // Resolved through the container rather than eagerly: asking for
        // the modules here would load every enabled package on every
        // request, including the ones that only serve a static asset.
        $this->app->resolving(
            PermissionRegistry::class,
            function (PermissionRegistry $registry, $app): void {
                $this->contributePermissions($registry, $app->make(ActiveModules::class));
            },
        );

        $this->registerTranslations();
    }

    /**
     * @return array<int, class-string>
     */
    public function provides(): array
    {
        return [ModuleCatalogue::class, ModuleLoader::class, ActiveModules::class, ClassLoader::class];
    }

    /**
     * A module's permissions become real permissions.
     *
     * They appear on the roles screen like any other, and they are orphaned
     * rather than deleted when the module goes — the mechanism the
     * permission registry has carried since Phase 1, finally used for what
     * the `module` field was added for.
     */
    private function contributePermissions(PermissionRegistry $registry, ActiveModules $modules): void
    {
        try {
            foreach ($modules->permissions() as $permission) {
                $registry->register($permission);
            }
        } catch (Throwable $exception) {
            // A module that cannot be read must not stop the platform
            // deciding what core permissions exist.
            report($exception);
        }
    }

    /**
     * Each module's `lang/` directory, under its own namespace.
     *
     * Namespaced so that a module cannot overwrite a core string: a package
     * that could redefine `billing.invoice_paid` could change what an
     * invoice says it is.
     */
    private function registerTranslations(): void
    {
        $catalogue = $this->app->make(ModuleCatalogue::class);

        foreach ($catalogue->all() as $manifest) {
            if (! $manifest->hasTranslations) {
                continue;
            }

            $path = $catalogue->pathFor($manifest->slug);

            if ($path !== null && is_dir($path.'/lang')) {
                Lang::addNamespace('module-'.$manifest->slug, $path.'/lang');
            }
        }
    }
}
