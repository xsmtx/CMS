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

    /** @var array<string, string> Namespace prefix => the module's `src`. */
    private array $prefixes = [];

    private bool $autoloading = false;

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
     *
     * **Modules get their own autoloader, prepended**, rather than relying
     * on the prefix registered with Composer's. Composer's loader caches
     * misses: anything that asked for one of these class names before the
     * module was enabled — a worker that had already seen the row, a
     * static analyser, a test scanning the tree — leaves it in
     * `missingClasses`, and no amount of `addPsr4` afterwards rescues it.
     * A module that could be permanently unloadable because something
     * looked at it too early is a module that fails on the one box where
     * it matters.
     *
     * The Composer prefix is registered too, because other tooling reads
     * it, but nothing depends on it resolving.
     */
    private function autoload(ModuleManifest $manifest, string $directory): void
    {
        if (isset($this->registered[$manifest->slug])) {
            return;
        }

        $this->registered[$manifest->slug] = true;
        $this->prefixes[$manifest->namespace] = $directory.'/src';

        $this->autoloader->addPsr4($manifest->namespace, $directory.'/src');

        if ($this->autoloading) {
            return;
        }

        $this->autoloading = true;

        spl_autoload_register($this->load(...), throw: true, prepend: true);
    }

    /**
     * Resolve a class inside a module this loader was told about.
     *
     * Bounded to the module's own `src`: the path is composed from the
     * manifest's namespace, resolved, and refused unless it really sits
     * inside that directory. A class name that tried to walk out with `..`
     * reaches nothing.
     */
    private function load(string $class): void
    {
        foreach ($this->prefixes as $prefix => $source) {
            if (! str_starts_with($class, $prefix)) {
                continue;
            }

            $relative = str_replace('\\', '/', substr($class, strlen($prefix)));
            $file = realpath($source.'/'.$relative.'.php');
            $root = realpath($source);

            if ($file === false || $root === false || ! str_starts_with($file, $root)) {
                return;
            }

            require_once $file;

            return;
        }
    }
}
