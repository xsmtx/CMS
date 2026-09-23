<?php

declare(strict_types=1);

namespace App\Infrastructure\Branding;

use App\Domain\Branding\Exceptions\InvalidTheme;
use App\Domain\Branding\Surface;
use App\Domain\Branding\ThemeManifest;

/**
 * Which themes are installed, and what each one inherits from.
 *
 * Reads `themes/<surface>/<slug>/theme.json` from disk. A theme is a
 * directory an operator dropped in, so everything here is defensive: a
 * manifest that will not parse names the file and is skipped rather than
 * taking the installation down, because one bad theme must not stop the
 * storefront serving pages with a good one.
 *
 * The **chain** is the load-bearing method. It returns a theme and its
 * ancestors, nearest first, which is exactly the order view paths are
 * registered in: the first level that has a template wins, whole.
 */
final class ThemeRegistry
{
    public const string FALLBACK = 'core';

    /** @var array<string, array<string, ThemeManifest>> */
    private array $themes = [];

    /** @var array<string, bool> */
    private array $scanned = [];

    /**
     * @return array<string, ThemeManifest> Keyed by slug.
     */
    public function all(Surface $surface): array
    {
        $this->scan($surface);

        return $this->themes[$surface->value] ?? [];
    }

    public function find(Surface $surface, string $slug): ?ThemeManifest
    {
        return $this->all($surface)[$slug] ?? null;
    }

    /**
     * A theme and its ancestors, nearest first.
     *
     * An unknown theme resolves to the core fallback rather than throwing:
     * an operator who deleted a theme directory should get the default
     * storefront, not a white page.
     *
     * @return list<ThemeManifest>
     */
    public function chain(Surface $surface, string $slug): array
    {
        $chain = [];
        $seen = [];
        $current = $this->find($surface, $slug) ?? $this->find($surface, self::FALLBACK);

        while ($current instanceof ThemeManifest) {
            if (isset($seen[$current->slug])) {
                throw InvalidTheme::circularParent($current->slug);
            }

            $seen[$current->slug] = true;
            $chain[] = $current;

            if ($current->parent === null) {
                break;
            }

            $parent = $this->find($surface, $current->parent);

            if (! $parent instanceof ThemeManifest) {
                throw InvalidTheme::missingParent($current->slug, $current->parent);
            }

            $current = $parent;
        }

        return $chain;
    }

    /**
     * Where a theme's templates live.
     */
    public function pathFor(Surface $surface, string $slug): string
    {
        return $surface->directory().'/'.$slug;
    }

    /**
     * Settings merged down the chain: the child's over its parent's.
     *
     * **Settings merge and templates do not**, which is the one asymmetry
     * in this whole feature and the one worth stating. A child theme that
     * wants to change one colour should not restate twenty; a child theme
     * that wants to change a header takes the whole header, because
     * splicing one template out of two produces bugs nobody can reason
     * about.
     *
     * @param  array<string, mixed>  $chosen
     * @return array<string, mixed>
     */
    public function settingsFor(Surface $surface, string $slug, array $chosen = []): array
    {
        $defaults = [];

        // Furthest ancestor first, so the nearest one wins.
        foreach (array_reverse($this->chain($surface, $slug)) as $manifest) {
            $defaults = [...$defaults, ...$manifest->settings];
        }

        return [...$defaults, ...$chosen];
    }

    private function scan(Surface $surface): void
    {
        if ($this->scanned[$surface->value] ?? false) {
            return;
        }

        $this->scanned[$surface->value] = true;
        $this->themes[$surface->value] = [];

        $directory = $surface->directory();

        if (! is_dir($directory)) {
            return;
        }

        foreach ((array) glob($directory.'/*/theme.json') as $path) {
            if (! is_string($path)) {
                continue;
            }

            $manifest = $this->read($path);

            if ($manifest instanceof ThemeManifest && $manifest->supports($surface)) {
                $this->themes[$surface->value][$manifest->slug] = $manifest;
            }
        }
    }

    private function read(string $path): ?ThemeManifest
    {
        $contents = file_get_contents($path);

        if ($contents === false) {
            return null;
        }

        $data = json_decode($contents, associative: true);

        if (! is_array($data)) {
            // Named, skipped, and the installation keeps serving. One bad
            // theme must not cost every page.
            report(InvalidTheme::unreadable($path));

            return null;
        }

        try {
            /** @var array<string, mixed> $data */
            return ThemeManifest::fromArray($data, $path);
        } catch (InvalidTheme $exception) {
            report($exception);

            return null;
        }
    }
}
