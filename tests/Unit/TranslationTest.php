<?php

declare(strict_types=1);

/**
 * Both locales say the same things.
 *
 * A key present in one file and missing from the other shows up as the raw
 * key on screen — `catalog.pricing.saved` where a sentence belongs — and
 * nobody notices until a customer does.
 *
 * @return array<string, string>
 */
function flattenTranslations(array $values, string $prefix = ''): array
{
    $flat = [];

    foreach ($values as $key => $value) {
        $path = $prefix === '' ? (string) $key : $prefix.'.'.$key;

        if (is_array($value)) {
            $flat = [...$flat, ...flattenTranslations($value, $path)];

            continue;
        }

        $flat[$path] = (string) $value;
    }

    return $flat;
}

it('translates every English key into Turkish', function (): void {
    $missing = [];

    foreach (glob(lang_path('en/*.php')) ?: [] as $file) {
        $name = basename($file);
        $turkish = lang_path('tr/'.$name);

        expect(file_exists($turkish))->toBeTrue("lang/tr/{$name} is missing.");

        $english = flattenTranslations(require $file);
        $translated = flattenTranslations(require $turkish);

        foreach (array_keys($english) as $key) {
            if (! array_key_exists($key, $translated)) {
                $missing[] = $name.':'.$key;
            }
        }
    }

    expect($missing)->toBe([]);
});

it('does not carry Turkish keys the English files have dropped', function (): void {
    $orphaned = [];

    foreach (glob(lang_path('tr/*.php')) ?: [] as $file) {
        $name = basename($file);
        $english = lang_path('en/'.$name);

        if (! file_exists($english)) {
            $orphaned[] = $name;

            continue;
        }

        $translated = flattenTranslations(require $file);
        $source = flattenTranslations(require $english);

        foreach (array_keys($translated) as $key) {
            if (! array_key_exists($key, $source)) {
                $orphaned[] = $name.':'.$key;
            }
        }
    }

    expect($orphaned)->toBe([]);
});

it('keeps the Turkish files in UTF-8 with their diacritics intact', function (): void {
    // An earlier pass folded them to ASCII, which turns "Müşteri" into
    // "Musteri" — readable, wrong, and the kind of thing that quietly ships.
    foreach (glob(lang_path('tr/*.php')) ?: [] as $file) {
        $contents = (string) file_get_contents($file);

        expect(mb_check_encoding($contents, 'UTF-8'))->toBeTrue(basename($file).' is not valid UTF-8.');
    }

    $identity = flattenTranslations(require lang_path('tr/identity.php'));

    expect($identity['statuses.suspended'])->toBe('Askıda');
});
