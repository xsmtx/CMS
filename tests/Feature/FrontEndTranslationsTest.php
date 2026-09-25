<?php

declare(strict_types=1);

use App\Support\View\FrontEndTranslations;
use Illuminate\Support\Arr;

/**
 * Every `t('…')` a component draws, against what the document actually
 * carries.
 *
 * `FrontEndTranslations` is an allow-list of paths, and the rule has always
 * been that adding `t('group.key')` to a component means adding its path
 * there. Nothing enforced it. The symptom is the designed one — `t()` renders
 * the key rather than an empty span — but it only works if somebody looks, and
 * the confirm-password screen printed `identity.auth.confirm_title` as its own
 * heading for two phases because nobody had.
 *
 * A call that passes a **fallback** is exempt: that third argument exists for
 * primitives, which can be mounted where no translations were rendered at all.
 */
function translationCalls(): array
{
    $root = base_path('resources/js');
    $calls = [];

    /** @var SplFileInfo $file */
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root)) as $file) {
        if (! $file->isFile() || ! in_array($file->getExtension(), ['vue', 'ts'], true)) {
            continue;
        }

        // A test file is allowed to ask for keys that do not exist; that is
        // often the point of the test.
        if (str_ends_with($file->getFilename(), '.test.ts')) {
            continue;
        }

        $contents = (string) file_get_contents($file->getPathname());

        preg_match_all("/\bt\(\s*'([^']+)'([^)]*)\)/", $contents, $matches, PREG_SET_ORDER);

        foreach ($matches as [, $key, $rest]) {
            // Two commas in the arguments means a fallback was given.
            if (substr_count($rest, ',') >= 2) {
                continue;
            }

            $calls[$key][] = str_replace($root.DIRECTORY_SEPARATOR, '', $file->getPathname());
        }
    }

    return $calls;
}

it('publishes every string a component asks for, in both locales', function (): void {
    $calls = translationCalls();

    expect($calls)->not->toBeEmpty();

    foreach (['en', 'tr'] as $locale) {
        $published = app(FrontEndTranslations::class)->forLocale($locale);

        $missing = [];

        foreach ($calls as $key => $files) {
            if (! is_string(Arr::get($published, $key))) {
                $missing[] = $key.'  ('.implode(', ', array_unique($files)).')';
            }
        }

        expect($missing)->toBe([], $locale.' is missing: '.PHP_EOL.implode(PHP_EOL, $missing));
    }
});
