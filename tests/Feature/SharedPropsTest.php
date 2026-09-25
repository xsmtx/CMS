<?php

declare(strict_types=1);

/**
 * A page prop must not be named like a shared one.
 *
 * `HandleInertiaRequests` shares a handful of props the shell reads on every
 * render — the brand, the actor, the flash bag. A page that sends a prop of
 * the same name wins for that screen only, and nothing anywhere says so: the
 * page works, the chrome around it quietly loses the object it was reading.
 *
 * It has happened twice. `/admin/settings` sent the raw, uninherited brand row
 * as `brand` and printed a copyright line with no company in it; the Connect
 * screen sent the brand's *name* as `brand` and did the same, while handing
 * `useBranding()` a string where it expected an object.
 *
 * The check is textual, and deliberately so: the alternative is rendering every
 * screen in the product and reading its props, which is the test suite this one
 * exists to be cheaper than. A payload key sits at twelve spaces because it is
 * the first level inside `Inertia::render([`; a nested key sits deeper, and
 * Pint keeps that true.
 */
$shared = static function (): array {
    $source = (string) file_get_contents(app_path('Http/Middleware/HandleInertiaRequests.php'));
    $share = mb_substr($source, (int) mb_strpos($source, 'public function share('));
    $share = mb_substr($share, 0, (int) mb_strpos($share, "\n    }\n"));

    preg_match_all("/^            '([a-zA-Z]+)' =>/m", $share, $matches);

    return array_values(array_unique($matches[1]));
};

/**
 * Only what is inside an `Inertia::render([ … ])`, keyed at its first level.
 *
 * A validation rule array sits at the same indentation as a payload key, so
 * scanning a whole file reported `ResellerController`'s `'locale' => [...]`
 * rule as a shadowed prop. The payload is sliced out by its own closing line
 * instead; Pint keeps the indentation honest.
 *
 * @return list<string>
 */
function renderedPayloads(string $contents): array
{
    // A render with no payload (`Inertia::render('Page');`) has no closing
    // line of its own, so a slice that only looked for one swallowed
    // everything up to the next screen — a validation array included.
    preg_match_all("/Inertia::render\('[^']+', \[
(.*?)
        \]\);/s", $contents, $matches);

    return $matches[1];
}

$renderingControllers = static function (): array {
    $found = [];

    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(app_path('Http/Controllers'), FilesystemIterator::SKIP_DOTS)
    );

    foreach ($files as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        $contents = (string) file_get_contents($file->getPathname());

        if (! str_contains($contents, 'Inertia::render(')) {
            continue;
        }

        $found[$file->getFilename()] = $contents;
    }

    return $found;
};

it('never names a page prop after a shared one', function () use ($shared, $renderingControllers): void {
    $names = $shared();
    $clashes = [];

    foreach ($renderingControllers() as $file => $contents) {
        foreach (renderedPayloads($contents) as $payload) {
            foreach ($names as $name) {
                if (preg_match("/^            '".$name."' =>/m", $payload) === 1) {
                    $clashes[] = $file.': '.$name;
                }
            }
        }
    }

    expect($clashes)->toBe([], 'These shadow a prop the shell reads: '.implode(', ', $clashes));
});

it('is actually looking at something', function () use ($shared, $renderingControllers): void {
    // An audit that checks nothing passes.
    $payloads = 0;

    foreach ($renderingControllers() as $contents) {
        $payloads += count(renderedPayloads($contents));
    }

    // Both halves: the shared names, and the payloads they are checked
    // against. A slicing regex that matched nothing would pass the check
    // above against every screen in the product.
    expect($shared())->toContain('brand')->toContain('auth')->toContain('flash')
        ->and($renderingControllers())->not->toBeEmpty()
        ->and($payloads)->toBeGreaterThan(50);
});
