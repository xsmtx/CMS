<?php

declare(strict_types=1);

/**
 * A helper in a Pest file is a global function, and two of them is a fatal.
 *
 * A test file has no namespace — which is why CLAUDE.md already says never to
 * `use` a global class in one. The sibling rule is this: `function reading()`
 * in one file and `function reading()` in another is "Cannot redeclare", and
 * it takes the whole suite down rather than one test. It is also invisible
 * until both files load in the same process, so a file written in isolation
 * passes and the suite does not.
 *
 * It was found the long way round: Rector reported a call to a three-argument
 * helper as having extra parameters, because it had resolved the *other*
 * function of that name. A static analyser disagreeing with the code is worth
 * reading twice.
 *
 * Only top-level declarations are counted. A closure assigned to a variable
 * is scoped to its file and is the right way to write a helper that might
 * clash.
 */
it('declares no test helper twice across the suite', function (): void {
    $declarations = [];

    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(base_path('tests'), FilesystemIterator::SKIP_DOTS),
    );

    foreach ($files as $file) {
        if (! $file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }

        $source = (string) file_get_contents($file->getPathname());

        // A declaration at the start of a line, which is what a top-level
        // function in a Pest file looks like. A method is indented and a
        // closure has no name.
        preg_match_all('/^function\s+(\w+)\s*\(/m', $source, $matches);

        foreach ($matches[1] as $name) {
            $declarations[$name][] = str_replace(base_path().DIRECTORY_SEPARATOR, '', $file->getPathname());
        }
    }

    // The guard on the guard: a regex that matched nothing would pass.
    expect($declarations)->not->toBeEmpty();

    $clashes = array_filter($declarations, static fn (array $files): bool => count($files) > 1);

    expect($clashes)->toBe([]);
});
