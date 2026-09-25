<?php

declare(strict_types=1);

use App\Infrastructure\Modules\ModuleCatalogue;
use App\Infrastructure\Modules\ModuleLoader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

pest()->extend(TestCase::class)->in('Unit');

/**
 * Make one official module's classes loadable, without enabling it.
 *
 * A module's `src` is not on Composer's autoload map — `ModuleLoader`
 * registers its own prepended loader per module, because Composer's caches
 * misses and nothing rescues a class name that was asked for too early.
 * Enabling is what normally registers it, and a test of the adapter *inside*
 * a package has no reason to enable anything: it constructs the class and
 * drives it against faked HTTP.
 *
 * So this registers the prefix and nothing else. It runs no migration, boots
 * no module and writes no row.
 */
function loadModuleClasses(string $slug): void
{
    $catalogue = app(ModuleCatalogue::class);
    $manifest = $catalogue->find($slug);

    if ($manifest === null) {
        throw new RuntimeException('No module called '.$slug.' on disk.');
    }

    app(ModuleLoader::class)
        ->instantiate($manifest, (string) $catalogue->pathFor($slug));
}
