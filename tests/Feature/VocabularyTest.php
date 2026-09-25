<?php

declare(strict_types=1);

use App\Application\Health\HealthChecks;
use App\Domain\Automation\AutomationTask;
use App\Domain\Licensing\Feature;
use App\Domain\Modules\ModuleType;

/**
 * Every word this platform shows has a word behind it.
 *
 * `useTranslations()` and `__()` both return the key when a string is missing,
 * deliberately: a visible `automation.tasks.webhooks.label` in the interface is
 * a bug report from the page itself, and blank space is a bug nobody files. That
 * only works if somebody is looking, and the first time anybody looked was in a
 * browser, months after the keys went missing — three screens were printing
 * their own translation keys at an operator.
 *
 * So this walks the enums whose members are **named on a screen** and asserts
 * each one resolves, in every locale this installation ships. It is the same
 * guard `AccessControlTest` puts on permission slugs, applied to the other half
 * of the vocabulary.
 *
 * A new member of any of these enums fails here until somebody writes its
 * wording, which is the point: adding a case is the moment to name it, not the
 * moment to ship a key.
 */

/**
 * Nothing here touches the container.
 *
 * A Pest dataset is built **before the application boots**, so a `app(...)` in
 * one resolves against a container whose providers have not registered. The
 * health checks are a registered set rather than an enum, so they are asked for
 * in a test body instead.
 *
 * Every enum below answers `labelKey()`; some also answer `descriptionKey()`.
 *
 * Only the ones an operator reads by name are listed. A status badge whose
 * wording is missing is loud and harmless; a task card titled with its own key
 * is what this exists to catch.
 *
 * @return list<array{0: string, 1: list<string>}>
 */
function vocabularyKeys(): array
{
    $keys = [];

    foreach (AutomationTask::cases() as $task) {
        $keys[] = ['AutomationTask::'.$task->name, [$task->labelKey(), $task->descriptionKey()]];
    }

    foreach (Feature::cases() as $feature) {
        $keys[] = ['Feature::'.$feature->name, [$feature->labelKey()]];
    }

    return $keys;
}

it('has wording for every name an operator reads', function (string $member, array $keys): void {
    foreach (config('platform.locales', ['en']) as $locale) {
        app()->setLocale($locale);

        foreach ($keys as $key) {
            $wording = (string) __($key);

            // `__()` returns the key itself when nothing is there. That is the
            // designed symptom, and this is the test that reads it.
            expect($wording)->not->toBe($key, $member.' has no wording for '.$key.' in '.$locale);
            expect(trim($wording))->not->toBe('', $member.' has empty wording for '.$key.' in '.$locale);
        }
    }

    app()->setLocale('en');
})->with(fn (): array => vocabularyKeys());

it('has wording for every health check that is registered', function (): void {
    // Asked of the registry rather than a list written here: a check added to
    // the container and nowhere else is exactly the one that would go unnamed,
    // and a hand-written list would not know about it. `licence` was.
    $reports = app(HealthChecks::class)->run();

    expect($reports)->not->toBeEmpty();

    foreach (config('platform.locales', ['en']) as $locale) {
        app()->setLocale($locale);

        foreach ($reports as $report) {
            $key = 'health.checks.'.$report->key;

            expect((string) __($key))->not->toBe($key, 'health check '.$report->key.' in '.$locale);
        }
    }

    app()->setLocale('en');
});

it('names every module type in both locales', function (): void {
    /*
     * Asked of the enum, not of a list here. `Infrastructure` was added for
     * the resource-graph phase and never given a label, so the Modules screen
     * printed `modules.types.infrastructure.label` at an operator beside a
     * package they were deciding whether to run — the permission-slug trap
     * through a third door.
     */
    foreach (config('platform.locales', ['en']) as $locale) {
        app()->setLocale($locale);

        foreach (ModuleType::cases() as $type) {
            foreach ([$type->labelKey(), $type->descriptionKey()] as $key) {
                expect((string) __($key))->not->toBe($key, $type->value.' in '.$locale);
            }
        }
    }

    app()->setLocale('en');
});

/**
 * Every enum in `app/Domain` that names itself.
 *
 * Found by walking the directory rather than by listing them, because the
 * one that goes unnamed is always the one somebody added last and nobody
 * added to a list. `ModuleType::Infrastructure` and every `CatalogStatus`
 * were printing their own key at an operator when this was written.
 */
function labelledEnums(): array
{
    $cases = [];

    foreach (
        new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path('Domain'))) as $file
    ) {
        if (! $file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }

        $relative = str_replace(
            [app_path('Domain'), DIRECTORY_SEPARATOR, '.php'],
            ['', '\\', ''],
            $file->getPathname(),
        );
        $class = 'App\\Domain'.$relative;

        if (! enum_exists($class) || ! method_exists($class, 'labelKey')) {
            continue;
        }

        foreach ($class::cases() as $case) {
            $cases[] = [$class, $case];
        }
    }

    return $cases;
}

it('names every enum case an operator reads, in both locales', function (): void {
    $enums = labelledEnums();

    expect($enums)->not->toBeEmpty();

    foreach (config('platform.locales', ['en']) as $locale) {
        app()->setLocale($locale);

        foreach ($enums as [$class, $case]) {
            $key = $case->labelKey();

            expect((string) __($key))->not->toBe(
                $key,
                class_basename($class).'::'.$case->name.' in '.$locale,
            );
        }
    }

    app()->setLocale('en');
});

it('is actually checking something', function (): void {
    // The guard on the guard. A dataset that silently became empty is a test
    // that passes by examining nothing, which is how an audit stops auditing.
    expect(count(vocabularyKeys()))->toBeGreaterThan(10)
        ->and(config('platform.locales'))->toContain('en')
        ->and(config('platform.locales'))->toContain('tr')
        ->and(count(ModuleType::cases()))->toBeGreaterThan(5)
        ->and(count(labelledEnums()))->toBeGreaterThan(100);
});

/**
 * Every licensing audit action an operator can read on the Licence screen.
 *
 * The screen used to derive English from the slug — `licensing.token.refused`
 * became "Token refused" — which reads as a translation right up until
 * somebody switches the panel to Turkish. The actions are string literals
 * rather than an enum, so this reads them out of the source the same way the
 * screen would have to: the ones audited directly, and the ones `accept()` is
 * called with.
 */
it('names every licensing audit action in both locales', function (): void {
    $sources = [
        app_path('Application/Licensing/Licensing.php'),
        app_path('Http/Controllers/Admin/LicenceController.php'),
    ];

    $actions = [];

    foreach ($sources as $file) {
        $contents = (string) file_get_contents($file);

        preg_match_all("/Audit::action\('licensing\.([a-z._]+)'\)/", $contents, $direct);
        preg_match_all("/action: '([a-z_]+)',/", $contents, $passed);

        $actions = [...$actions, ...$direct[1], ...$passed[1]];
    }

    $actions = array_values(array_unique($actions));

    expect($actions)->not->toBeEmpty('No licensing actions were found to check.');

    foreach ($actions as $action) {
        $key = 'licensing.audit.'.str_replace('.', '_', $action);

        foreach (['en', 'tr'] as $locale) {
            $wording = trans($key, [], $locale);

            expect($wording)->not->toBe($key, $action.' has no wording in '.$locale);
        }
    }
});
