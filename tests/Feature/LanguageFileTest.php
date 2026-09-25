<?php

declare(strict_types=1);

use Illuminate\Support\Arr;

/**
 * The language files, checked for the mistakes a file of strings invites.
 *
 * A duplicate key inside one array is the dangerous one: PHP takes the last
 * and says nothing, so a screen quietly keeps the old wording while the file
 * shows the new one right above it. It happened here — `catalog.groups.create`
 * was added a second time and the button went on saying "New group".
 *
 * The two locales are compared as a pair for the same reason the suite checks
 * anything twice: a key that exists only in English is a key that renders as
 * itself for everybody else.
 */
function languageFiles(): array
{
    $files = [];

    foreach (glob(lang_path('en/*.php')) ?: [] as $path) {
        $files[basename($path)] = $path;
    }

    return $files;
}

/**
 * @return list<string>
 */
function duplicateKeys(string $path): array
{
    $lines = file($path, FILE_IGNORE_NEW_LINES) ?: [];
    $seen = [];
    $duplicates = [];
    $stack = [];
    $anonymous = 0;

    foreach ($lines as $line) {
        $trimmed = trim($line);

        if (preg_match("/^'([^']+)' => \[$/", $trimmed, $match) === 1) {
            $stack[] = $match[1];

            continue;
        }

        /*
         * An anonymous `[` opens a list — the onboarding steps, each with its
         * own title and body. Without following it, every step after the first
         * looks like the same key stated twice.
         */
        if ($trimmed === '[') {
            $stack[] = '*'.$anonymous++;

            continue;
        }

        if ($trimmed === '],' || $trimmed === ']') {
            array_pop($stack);

            continue;
        }

        if (preg_match("/^'([^']+)' => /", $trimmed, $match) !== 1) {
            continue;
        }

        $key = implode('.', [...$stack, $match[1]]);

        if (isset($seen[$key])) {
            $duplicates[] = $key;
        }

        $seen[$key] = true;
    }

    return $duplicates;
}

it('never states the same key twice in one file', function (): void {
    $found = [];

    foreach (languageFiles() as $name => $path) {
        foreach (['en/'.$name => $path, 'tr/'.$name => lang_path('tr/'.$name)] as $label => $file) {
            if (! is_file($file)) {
                continue;
            }

            foreach (duplicateKeys($file) as $key) {
                $found[] = $label.': '.$key;
            }
        }
    }

    expect($found)->toBe([], 'Duplicated, and the later one silently wins: '.PHP_EOL.implode(PHP_EOL, $found));
});

it('says everything in both languages', function (): void {
    $missing = [];

    foreach (languageFiles() as $name => $path) {
        $turkish = lang_path('tr/'.$name);

        expect($turkish)->toBeFile("lang/tr/{$name} does not exist");

        $english = Arr::dot(require $path);
        $other = Arr::dot(require $turkish);

        foreach (array_keys($english) as $key) {
            if (! array_key_exists($key, $other)) {
                $missing[] = 'tr/'.$name.': '.$key;
            }
        }

        foreach (array_keys($other) as $key) {
            if (! array_key_exists($key, $english)) {
                $missing[] = 'en/'.$name.': '.$key;
            }
        }
    }

    expect($missing)->toBe([], 'Present in one language only: '.PHP_EOL.implode(PHP_EOL, array_slice($missing, 0, 40)));
});

/**
 * The framework's own sentences, which are not in `lang/` until somebody
 * publishes them.
 *
 * Every validation error in this product — on every admin form and every
 * customer one — came out of Laravel's built-in English, so a Turkish
 * operator filling in a Turkish form was told "The event field is
 * required." It was invisible because no test ever read a refusal in
 * Turkish, and no screen shows one until somebody makes a mistake.
 */
it('answers in Turkish when the framework refuses something', function (): void {
    $lines = [
        'validation.required' => ['attribute' => 'ad'],
        'auth.failed' => [],
        'passwords.sent' => [],
        'pagination.next' => [],
    ];

    foreach ($lines as $key => $replace) {
        $english = trans($key, $replace, 'en');
        $turkish = trans($key, $replace, 'tr');

        expect($turkish)->not->toBe($key, $key.' is missing from lang/tr')
            ->and($turkish)->not->toBe($english, $key.' is still the English sentence');
    }
});
