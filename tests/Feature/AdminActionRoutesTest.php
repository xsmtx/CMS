<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Symfony\Component\Finder\Finder;

/**
 * Every `/admin` path a screen names, checked against the router.
 *
 * This exists because of a bug that shipped and could not be seen. The fleet
 * screen moved behind the Apps door; its routes became
 * `/admin/apps/infrastructure/…` and the page went on posting to
 * `/admin/infrastructure/…`. Adding a server, editing one, deleting one,
 * adding a group and testing a connection all answered 404 — and pressing a
 * button that does nothing looks exactly like a slow network.
 *
 * Nothing caught it. The screen had a feature test and the test rendered the
 * screen. Phase 9's lesson was "a screen with no test that renders it has not
 * been tested"; this is the one after it: **a path a page names is a promise,
 * and nothing was checking the promises.**
 *
 * It is a grep rather than a browser, which is what makes it cheap enough to
 * run on every commit. A path built entirely at runtime is invisible to it,
 * which is the honest limit — so the guard at the foot asserts it is still
 * finding paths at all.
 */

/**
 * Every literal `/admin/...` in the Vue pages, with `${…}` standing in for a
 * route parameter.
 *
 * @return array<string, array{0: string, 1: string}>
 */
function declaredAdminPaths(): array
{
    $found = [];

    // `dirname()` rather than `resource_path()`: a dataset is built while the
    // file is being collected, which is before the application is booted, so a
    // helper that reaches for the container would find nothing.
    $files = Finder::create()
        ->files()
        ->in(dirname(__DIR__, 2).'/resources/js')
        ->name('*.vue');

    foreach ($files as $file) {
        $text = $file->getContents();

        preg_match_all('#[\'"`](/admin/[^\'"`\s?\#]*)#', $text, $matches);

        foreach ($matches[1] as $path) {
            // A template literal's `${expression}` is one segment, and what
            // is in it is unknowable here: on one screen it is a union of
            // literals and on the next an id. `{}` means "some segment".
            $normalised = (string) preg_replace('#\$\{[^}]*\}#', '{}', $path);
            $normalised = rtrim($normalised, '/');

            if ($normalised === '' || $normalised === '/admin') {
                continue;
            }

            // Keyed, so one path named on forty screens is checked once.
            $found[$normalised] = [$normalised, $file->getRelativePathname()];
        }
    }

    ksort($found);

    return $found;
}

/**
 * Whether any route would answer that path, by any method.
 *
 * Segment by segment rather than by regular expression, because both sides
 * have wildcards: `{param}` on the route, and `{}` where the page interpolated
 * something. Two wildcards meeting is a match — all this can honestly say is
 * that a route of that *shape* exists.
 *
 * The method is deliberately not compared. A `DELETE` written as a `POST` is a
 * different kind of mistake; this is looking for paths nothing answers at all.
 */
function routeAnswers(string $path): bool
{
    $wanted = explode('/', trim($path, '/'));

    foreach (Route::getRoutes()->getRoutes() as $route) {
        $offered = explode('/', trim($route->uri(), '/'));

        if (count($offered) !== count($wanted)) {
            continue;
        }

        $matches = true;

        foreach ($offered as $index => $segment) {
            $theirs = $wanted[$index];

            // A route parameter takes any segment; so does one the page
            // interpolated.
            if (str_starts_with($segment, '{') || $theirs === '{}') {
                continue;
            }

            if ($segment !== $theirs) {
                $matches = false;

                break;
            }
        }

        if ($matches) {
            return true;
        }
    }

    return false;
}

it('routes every admin path a screen names', function (string $path, string $file): void {
    expect(routeAnswers($path))->toBeTrue(
        "{$file} names {$path}, and no route answers it. A button that posts to a "
        .'path nothing serves looks like nothing happening.'
    );
})->with(fn (): array => declaredAdminPaths());

/**
 * The guard on the guard. A regular expression that stopped matching would
 * make every case above pass by examining nothing, which is the failure mode
 * of every test that scans source.
 */
it('is still finding paths to check', function (): void {
    $paths = declaredAdminPaths();

    expect(count($paths))->toBeGreaterThan(60)
        ->and(array_keys($paths))->toContain('/admin/apps/infrastructure/servers');
});
