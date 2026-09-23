<?php

declare(strict_types=1);

namespace App\Infrastructure\Modules;

use App\Domain\Modules\Contracts\Module;
use App\Domain\Modules\Exceptions\InvalidModule;
use App\Domain\Modules\ModuleManifest;
use App\Domain\Modules\Sdk;
use Composer\Autoload\ClassLoader;

/**
 * The one place a module's PHP is brought into the process.
 *
 * Kept apart from `ModuleCatalogue` deliberately. Reading a manifest is
 * safe and happens whenever somebody opens a screen; loading a class is the
 * act the whole lifecycle exists to gate, and it happens only for a module
 * whose row says so ([ADR 0038](../../../docs/adr/0038-a-module-may-execute.md)).
 *
 * The autoload prefix is registered here rather than in `composer.json`,
 * because `composer.json` is the platform's and a module is not. An
 * operator installing a module must not have to run `composer dump-autoload`
 * as root on a production box.
 *
 * **Compatibility is checked before construction, not after.** A module
 * built against a different SDK is refused while it is still only a string
 * in a JSON file.
 */
final class ModuleLoader
{
    /** @var array<string, true> */
    private array $registered = [];

    public function __construct(
        private readonly ClassLoader $autoloader,
        private readonly string $platformVersion,
    ) {}

    /**
     * Check a manifest against this platform, and say so if it does not fit.
     *
     * @throws InvalidModule
     */
    public function assertCompatible(ModuleManifest $manifest): void
    {
        // An unreadable range is refused rather than assumed permissive.
        // "Nobody can read this" and "your platform is too old" send an
        // operator to different places.
        if (! $manifest->sdk->isUnderstood()) {
            throw InvalidModule::unreadableRange($manifest->slug, (string) $manifest->sdk);
        }

        if (! $manifest->platform->isUnderstood()) {
            throw InvalidModule::unreadableRange($manifest->slug, (string) $manifest->platform);
        }

        if (! $manifest->sdk->allows(Sdk::VERSION)) {
            throw InvalidModule::incompatibleSdk(
                $manifest->slug,
                (string) $manifest->sdk,
                Sdk::VERSION,
            );
        }

        if (! $manifest->platform->allows($this->platformVersion)) {
            throw InvalidModule::incompatiblePlatform(
                $manifest->slug,
                (string) $manifest->platform,
                $this->platformVersion,
            );
        }
    }

    /**
     * Build a module's entrypoint.
     *
     * `new` with no arguments on purpose: a module whose construction
     * needed something from the container would be a module the container
     * had to know about, and the container is exactly what this SDK keeps
     * out of a module's reach. Everything it needs arrives in `boot()`.
     *
     * @throws InvalidModule
     */
    public function instantiate(ModuleManifest $manifest, string $directory): Module
    {
        $this->assertCompatible($manifest);
        $this->autoload($manifest, $directory);

        $class = $manifest->entrypointClass();

        if (! class_exists($class)) {
            throw InvalidModule::missingEntrypoint($manifest->slug, $class);
        }

        $instance = new $class;

        if (! $instance instanceof Module) {
            throw InvalidModule::notAModule($manifest->slug, $class);
        }

        return $instance;
    }

    /**
     * Point the autoloader at one module's `src`, once.
     */
    private function autoload(ModuleManifest $manifest, string $directory): void
    {
        if (isset($this->registered[$manifest->slug])) {
            return;
        }

        $this->registered[$manifest->slug] = true;

        $this->autoloader->addPsr4($manifest->namespace, $directory.'/src');
    }
}
