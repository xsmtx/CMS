<?php

declare(strict_types=1);

namespace App\Infrastructure\Modules;

use App\Domain\Modules\Exceptions\InvalidModule;
use App\Domain\Modules\ModuleManifest;

/**
 * What is on disk, and nothing more.
 *
 * This class reads JSON. It never loads a class, never runs a line of a
 * package and never decides anything is trustworthy — that is the whole
 * point of separating it from `ModuleLoader`. An operator opening the
 * modules screen has, by that act, agreed to nothing.
 *
 * Everything here is defensive, for the same reason the theme registry is:
 * a module is a directory somebody dropped in. A manifest that will not
 * parse is **named and skipped** rather than thrown, because one bad
 * package must not stop the screen that would let an operator delete it.
 *
 * Layout is `modules/<vendor>/<slug>/module.json`. The vendor level exists
 * so that two authors can both ship a module called `slack` without one
 * overwriting the other on disk — the slug still has to be unique across
 * the installation, and install refuses a second one, but a collision
 * should be a refusal rather than a silently replaced directory.
 */
final class ModuleCatalogue
{
    /** @var array<string, ModuleManifest>|null */
    private ?array $manifests = null;

    /** @var array<string, string> */
    private array $paths = [];

    public function __construct(private readonly string $root) {}

    /**
     * Every readable manifest, keyed by slug.
     *
     * @return array<string, ModuleManifest>
     */
    public function all(): array
    {
        return $this->manifests ??= $this->scan();
    }

    public function find(string $slug): ?ModuleManifest
    {
        return $this->all()[$slug] ?? null;
    }

    /**
     * The absolute directory a module was found in.
     */
    public function pathFor(string $slug): ?string
    {
        $this->all();

        return $this->paths[$slug] ?? null;
    }

    /**
     * The path stored on the row: relative, so that moving an installation
     * between machines does not invalidate every module.
     */
    public function relativePathFor(string $slug): ?string
    {
        $path = $this->pathFor($slug);

        if ($path === null) {
            return null;
        }

        $relative = str_replace('\\', '/', substr($path, strlen($this->root)));

        return trim($relative, '/');
    }

    public function forget(): void
    {
        $this->manifests = null;
        $this->paths = [];
    }

    /**
     * @return array<string, ModuleManifest>
     */
    private function scan(): array
    {
        $found = [];
        $this->paths = [];

        if (! is_dir($this->root)) {
            return $found;
        }

        foreach ((array) glob($this->root.'/*/*/module.json') as $path) {
            if (! is_string($path)) {
                continue;
            }

            $manifest = $this->read($path);

            if (! $manifest instanceof ModuleManifest) {
                continue;
            }

            if (isset($found[$manifest->slug])) {
                // Two packages claiming one slug. Neither is chosen: a
                // silent winner here means an operator enabling one module
                // and running another.
                report(InvalidModule::badSlug($path, $manifest->slug));

                continue;
            }

            $found[$manifest->slug] = $manifest;
            $this->paths[$manifest->slug] = dirname($path);
        }

        return $found;
    }

    private function read(string $path): ?ModuleManifest
    {
        $contents = file_get_contents($path);

        if ($contents === false) {
            return null;
        }

        $data = json_decode($contents, associative: true);

        if (! is_array($data)) {
            report(InvalidModule::unreadable($path));

            return null;
        }

        try {
            /** @var array<string, mixed> $data */
            return ModuleManifest::fromArray($data, $path);
        } catch (InvalidModule $exception) {
            report($exception);

            return null;
        }
    }
}
