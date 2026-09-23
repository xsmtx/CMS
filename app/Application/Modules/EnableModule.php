<?php

declare(strict_types=1);

namespace App\Application\Modules;

use App\Domain\Modules\Exceptions\InvalidModule;
use App\Domain\Modules\ModuleContext;
use App\Domain\Modules\ModuleState;
use App\Infrastructure\Modules\ActiveModules;
use App\Infrastructure\Modules\ChannelModuleLogger;
use App\Infrastructure\Modules\Models\ModuleRecord;
use App\Infrastructure\Modules\ModuleCatalogue;
use App\Infrastructure\Modules\ModuleLoader;
use App\Support\Audit\Facades\Audit;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Artisan;
use Throwable;

/**
 * The one moment an operator agrees to run somebody else's code.
 *
 * Everything that executes happens here and in this order, because each
 * step is a reason to stop before the next:
 *
 * 1. Its dependencies are enabled. A module built on another one is
 *    refused rather than half-working.
 * 2. Its configuration is complete. A gateway registered without its API
 *    key fails at the moment a customer is waiting, which is the failure
 *    the registries have always been written to avoid.
 * 3. Its migrations run. A migration is a class the module author wrote,
 *    so this is already running the package — which is why it is here and
 *    not in `InstallModule`.
 * 4. Its entrypoint is constructed and **inspected** before anything it
 *    returned reaches a registry.
 * 5. It is booted, and what it registered is written down.
 *
 * **Anything thrown leaves the module `failed` with the reason**, not
 * `enabled` and not half-registered. One bad package must not take an
 * installation down, and an operator who gets "it did not work" with no
 * sentence attached reinstalls it three times.
 */
final readonly class EnableModule
{
    public function __construct(
        private ModuleCatalogue $catalogue,
        private ModuleLoader $loader,
        private InspectModule $inspector,
        private ActiveModules $runtime,
    ) {}

    public function handle(ModuleRecord $record, ?Model $actor = null): ModuleRecord
    {
        if (! (bool) config('platform.modules.enabled', true)) {
            throw InvalidModule::disabledByConfiguration();
        }

        $manifest = $this->catalogue->find($record->slug);

        if ($manifest === null) {
            throw InvalidModule::notFound($record->slug);
        }

        $directory = $this->catalogue->pathFor($record->slug);

        if ($directory === null) {
            throw InvalidModule::notFound($record->slug);
        }

        $this->assertDependenciesEnabled($manifest->slug, $manifest->dependencies);

        try {
            $this->loader->assertCompatible($manifest);

            $module = $this->loader->instantiate($manifest, $directory);

            $context = new ModuleContext(
                $record->slug,
                new ChannelModuleLogger($record->slug),
                $record->config ?? [],
            );

            $missing = $context->missing($module->configSchema());

            if ($missing !== []) {
                throw InvalidModule::missingConfiguration($record->slug, implode(', ', $missing));
            }

            // Before `boot()`: a module refused for claiming the wrong type
            // must not have had a chance to do anything first.
            $registration = $this->inspector->handle($manifest, $module);

            $this->migrate($directory);

            $module->boot($context);
        } catch (Throwable $exception) {
            $record->forceFill([
                'state' => ModuleState::Failed->value,
                'failure_reason' => $exception->getMessage(),
                'disabled_at' => CarbonImmutable::now(),
            ])->save();

            Audit::action('modules.failed')
                ->by($actor)
                ->on($record)
                ->because($exception->getMessage())
                ->write();

            throw $exception;
        }

        $record->forceFill([
            'state' => ModuleState::Enabled->value,
            'version' => $manifest->version,
            'failure_reason' => null,
            'capabilities' => $registration->toArray(),
            'enabled_at' => CarbonImmutable::now(),
            'disabled_at' => null,
        ])->save();

        // The runtime may already have been resolved in this request — the
        // permission registry asks it what modules add — so it is told to
        // forget. Without this the screen that just changed a module goes
        // on describing the one it replaced, which is the commonest way a
        // cache like this is wrong and the one moment somebody is watching.
        $this->runtime->forget();

        Audit::action('modules.enabled')
            ->by($actor)
            ->on($record)
            ->withMetadata([
                'version' => $manifest->version,
                // What they agreed to, written down at the moment they
                // agreed to it.
                'registers' => $registration->toArray(),
            ])
            ->write();

        return $record;
    }

    /**
     * @param  list<string>  $dependencies
     */
    private function assertDependenciesEnabled(string $slug, array $dependencies): void
    {
        foreach ($dependencies as $dependency) {
            $enabled = ModuleRecord::query()
                ->where('slug', $dependency)
                ->where('state', ModuleState::Enabled->value)
                ->exists();

            if (! $enabled) {
                throw InvalidModule::missingDependency($slug, $dependency);
            }
        }
    }

    /**
     * Run whatever the module has not run yet.
     *
     * Laravel's migration repository is shared, so a module's migrations
     * are recorded beside core's and running this twice is a no-op. That is
     * the behaviour wanted: enabling a module that was disabled for a month
     * should apply whatever arrived in the meantime, and nothing else.
     */
    private function migrate(string $directory): void
    {
        if (! is_dir($directory.'/database/migrations')) {
            return;
        }

        Artisan::call('migrate', [
            '--path' => $directory.'/database/migrations',
            '--realpath' => true,
            '--force' => true,
        ]);
    }
}
