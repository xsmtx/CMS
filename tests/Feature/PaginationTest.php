<?php

declare(strict_types=1);

/**
 * A list that paginates has to offer a way to turn the page.
 *
 * Fourteen screens did not. They printed "Page 1 of 3 — 47 invoices" as plain
 * text and rendered no control at all, so an operator with more than
 * twenty-five invoices could not reach the older ones — and the page number
 * told them exactly how many they were missing. Three portal lists had the
 * same gap.
 *
 * Nothing caught it because every one of those screens rendered, every prop
 * was asserted, and a paginator that is never linked is a paginator that
 * still paginates correctly. So the rule is checked at the two places it can
 * be stated: a controller that paginates sends the links, and a screen that
 * receives them draws a pager.
 */
function paginatingControllers(): array
{
    $files = [];

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path('Http/Controllers'))) as $file) {
        if (! $file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }

        $contents = (string) file_get_contents($file->getPathname());

        if (str_contains($contents, '->currentPage()')) {
            $files[str_replace(app_path().DIRECTORY_SEPARATOR, '', $file->getPathname())] = $contents;
        }
    }

    return $files;
}

function paginatedPages(): array
{
    $files = [];

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(resource_path('js/Pages'))) as $file) {
        if (! $file->isFile() || $file->getExtension() !== 'vue') {
            continue;
        }

        $contents = (string) file_get_contents($file->getPathname());

        if (str_contains($contents, 'lastPage')) {
            $files[str_replace(resource_path('js/Pages').DIRECTORY_SEPARATOR, '', $file->getPathname())] = $contents;
        }
    }

    return $files;
}

it('sends the links with every paginated payload', function (): void {
    $missing = [];

    foreach (paginatingControllers() as $path => $contents) {
        if (! str_contains($contents, '->linkCollection()')) {
            $missing[] = $path;
        }
    }

    expect($missing)->toBe([], 'These paginate and send no links: '.implode(', ', $missing));
});

it('draws a pager on every screen that is given one', function (): void {
    /*
     * There is no exemption. The Resource Graph's two screens kept a
     * hand-written previous/next pair for a while, which meant two English
     * words nothing translated and no way to reach page seven of a fleet.
     */
    $missing = [];

    foreach (paginatedPages() as $path => $contents) {
        if (! str_contains($contents, 'AppPagination')) {
            $missing[] = $path;
        }
    }

    expect($missing)->toBe([], 'These print a page number and offer no way to turn it: '.implode(', ', $missing));
});

it('is actually looking at something', function (): void {
    // An audit that checks nothing passes.
    expect(paginatingControllers())->not->toBeEmpty()
        ->and(paginatedPages())->not->toBeEmpty();
});
