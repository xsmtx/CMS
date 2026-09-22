<?php

declare(strict_types=1);

namespace App\Providers;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use RuntimeException;

final class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        $this->configureModels();
        $this->configureFactories();
        $this->configureDates();
        $this->configureUrls();
        $this->configurePasswords();
    }

    /**
     * Strict mode turns silent data bugs into loud failures: accessing an
     * attribute that was not loaded, lazily loading a relation inside a loop
     * or assigning to a non-fillable attribute all throw outside production.
     */
    private function configureModels(): void
    {
        Model::shouldBeStrict(! $this->app->isProduction());
        Model::unguard(false);
    }

    /**
     * Models live in per-context namespaces rather than a single App\Models
     * folder, so Laravel's default factory-name guess does not apply. All
     * factories stay in Database\Factories, keyed by the model's short name.
     */
    private function configureFactories(): void
    {
        Factory::guessFactoryNamesUsing($this->factoryNameFor(...));
    }

    /**
     * @param  class-string<Model>  $modelName
     * @return class-string<Factory<Model>>
     */
    private function factoryNameFor(string $modelName): string
    {
        $factory = 'Database\\Factories\\'.class_basename($modelName).'Factory';

        if (! is_subclass_of($factory, Factory::class)) {
            throw new RuntimeException(
                "No factory for model [{$modelName}]: expected [{$factory}] in database/factories."
            );
        }

        return $factory;
    }

    /**
     * Immutable dates by default. Mutating a Carbon instance that another
     * object still holds a reference to is a recurring source of off-by-one
     * billing-period bugs.
     */
    private function configureDates(): void
    {
        Date::use(CarbonImmutable::class);
    }

    private function configureUrls(): void
    {
        if ($this->app->isProduction()) {
            URL::forceHttps();
        }
    }

    private function configurePasswords(): void
    {
        Password::defaults(function (): Password {
            $rule = Password::min(12)->letters()->numbers()->symbols();

            return $this->app->isProduction() ? $rule->uncompromised() : $rule;
        });
    }
}
