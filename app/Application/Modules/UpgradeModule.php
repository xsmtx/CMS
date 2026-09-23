<?php

declare(strict_types=1);

namespace App\Application\Modules;

use App\Domain\Modules\Exceptions\InvalidModule;
use App\Domain\Modules\ModuleState;
use App\Infrastructure\Modules\Models\ModuleRecord;
use App\Infrastructure\Modules\ModuleCatalogue;
use App\Infrastructure\Modules\ModuleLoader;
use App\Support\Audit\Facades\Audit;
use Illuminate\Database\Eloquent\Model;

/**
 * Take the version that is now on disk.
 *
 * An upgrade is files replaced by an operator and then this, which is the
 * platform catching up: check the new manifest still fits, refuse a
 * version that moves backwards, and — if the module was running — enable it
 * again so its migrations run and whatever it registers is re-read.
 *
 * **Downgrades are refused rather than handled.** Running a migration
 * backwards is something only the module's author can reason about, and a
 * platform that pretended otherwise would corrupt somebody's data politely.
 * An operator who wants the older version uninstalls and installs it, and
 * is told so.
 */
final readonly class UpgradeModule
{
    public function __construct(
        private ModuleCatalogue $catalogue,
        private ModuleLoader $loader,
        private EnableModule $enable,
    ) {}

    public function handle(ModuleRecord $record, ?Model $actor = null): ModuleRecord
    {
        $this->catalogue->forget();

        $manifest = $this->catalogue->find($record->slug);

        if ($manifest === null) {
            throw InvalidModule::notFound($record->slug);
        }

        if (version_compare($manifest->version, $record->version, '<')) {
            throw InvalidModule::downgrade($record->slug, $record->version, $manifest->version);
        }

        $this->loader->assertCompatible($manifest);

        $from = $record->version;
        $wasRunning = $record->state === ModuleState::Enabled;

        $record->forceFill([
            'name' => $manifest->name,
            'type' => $manifest->type->value,
            'version' => $manifest->version,
            'provider' => $manifest->provider,
            'path' => $this->catalogue->relativePathFor($record->slug) ?? $record->path,
        ])->save();

        Audit::action('modules.upgraded')
            ->by($actor)
            ->on($record)
            ->withMetadata(['from' => $from, 'to' => $manifest->version])
            ->write();

        if ($wasRunning) {
            // Re-enabled rather than left alone: the new version's
            // migrations have not run, and what it registers may have
            // changed. A module that was running before an upgrade should
            // be running after one.
            return $this->enable->handle($record->refresh(), $actor);
        }

        return $record;
    }
}
