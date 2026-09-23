<?php

declare(strict_types=1);

namespace App\Application\Branding;

use App\Domain\Branding\Exceptions\InvalidTheme;
use App\Domain\Branding\Surface;
use App\Domain\Branding\ThemeManifest;
use App\Infrastructure\Branding\ThemeRegistry;

/**
 * Decides whether a theme is safe to serve pages from.
 *
 * **A theme may not contain PHP that runs.** This is the check that makes
 * that true rather than aspirational.
 *
 * A theme is content an operator downloads and drops into a directory.
 * WHMCS template files are PHP, and "install this free theme" is a known
 * way into a hosting company. Blade is a template language that compiles
 * under this application's own escaping; raw `<?php` inside a Blade file
 * is arbitrary code running as the web user, before any authorization
 * decision has been made.
 *
 * So raw PHP tags are refused, by name and by file. Blade's own directives
 * are fine: `@if`, `@foreach` and `@include` compile exactly as they do in
 * a core template.
 *
 * A theme that genuinely needs behaviour is a **module** (Phase 12), which
 * is a different trust decision with a different review.
 */
final readonly class InspectTheme
{
    /**
     * Tags that mean "run this". `<?xml` is deliberately not one of them:
     * a theme rendering an XML sitemap is a legitimate thing to want, and
     * PHP only treats it as an open tag when short tags are on — which
     * this platform requires to be off.
     */
    private const array FORBIDDEN = ['<?php', '<?=', '<%'];

    public function __construct(private ThemeRegistry $registry) {}

    /**
     * Every problem with a theme, or an empty list.
     *
     * A list rather than the first failure: an operator fixing a theme
     * wants to see all four bad files at once, not to find them one
     * upload at a time.
     *
     * @return list<string>
     */
    public function problems(Surface $surface, string $slug): array
    {
        $manifest = $this->registry->find($surface, $slug);

        if (! $manifest instanceof ThemeManifest) {
            return ["Theme [{$slug}] is not installed for the {$surface->value} surface."];
        }

        $problems = [];

        $platform = (string) config('platform.version', '1.0.0');

        if (! $manifest->isCompatibleWith($platform)) {
            $problems[] = InvalidTheme::incompatible(
                $manifest->slug,
                $manifest->compatibility,
                $platform,
            )->getMessage();
        }

        if ($manifest->parent !== null && ! $this->registry->find($surface, $manifest->parent) instanceof ThemeManifest) {
            $problems[] = InvalidTheme::missingParent($manifest->slug, $manifest->parent)->getMessage();
        }

        foreach ($this->templates($surface, $manifest->slug) as $file) {
            if ($this->executes($file)) {
                $problems[] = InvalidTheme::executable($file)->getMessage();
            }
        }

        return $problems;
    }

    public function isSafe(Surface $surface, string $slug): bool
    {
        return $this->problems($surface, $slug) === [];
    }

    private function executes(string $file): bool
    {
        $contents = file_get_contents($file);

        if ($contents === false) {
            // Unreadable is not safe. A file the platform cannot inspect
            // is a file it should not compile.
            return true;
        }

        return array_any(self::FORBIDDEN, fn (string $tag): bool => str_contains($contents, $tag));
    }

    /**
     * @return list<string>
     */
    private function templates(Surface $surface, string $slug): array
    {
        $directory = $this->registry->pathFor($surface, $slug).'/views';

        if (! is_dir($directory)) {
            return [];
        }

        $files = [];

        /** @var list<string> $found */
        $found = (array) glob($directory.'/{,*/,*/*/}*.blade.php', GLOB_BRACE);

        foreach ($found as $file) {
            if (is_string($file) && is_file($file)) {
                $files[] = $file;
            }
        }

        return $files;
    }
}
