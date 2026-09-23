<?php

declare(strict_types=1);

namespace App\Application\Modules;

use App\Domain\Modules\Exceptions\InvalidModule;
use App\Domain\Modules\ModuleState;
use App\Infrastructure\Modules\Models\ModuleRecord;
use App\Infrastructure\Modules\ModuleCatalogue;
use App\Infrastructure\Modules\ModuleLoader;
use App\Support\Audit\Facades\Audit;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * Take note of a module. Nothing of it runs.
 *
 * The plan for this phase originally had install running the module's
 * migrations and syncing its permissions, and called that "not consent".
 * Writing it showed that to be false: a migration is a PHP class the module
 * author wrote, so running one *is* running the module. Consent cannot be
 * split across two steps where the earlier one already executes the
 * package.
 *
 * So installing is exactly this: read the manifest, check it fits, write a
 * row. Migrations and permissions belong to `EnableModule`, which is the
 * one audited moment where an operator agrees to run somebody else's code
 * ([ADR 0038](../../../docs/adr/0038-a-module-may-execute.md)).
 */
final readonly class InstallModule
{
    public function __construct(
        private ModuleCatalogue $catalogue,
        private ModuleLoader $loader,
    ) {}

    public function handle(string $slug, ?Model $actor = null): ModuleRecord
    {
        if (! (bool) config('platform.modules.enabled', true)) {
            throw InvalidModule::disabledByConfiguration();
        }

        $manifest = $this->catalogue->find($slug);

        if ($manifest === null) {
            throw InvalidModule::notFound($slug);
        }

        if (ModuleRecord::query()->where('slug', $slug)->exists()) {
            throw InvalidModule::alreadyInstalled($slug);
        }

        // Before the row, not after: a refusal should leave no trace of a
        // module this platform will not run.
        $this->loader->assertCompatible($manifest);

        $record = ModuleRecord::query()->create([
            'slug' => $manifest->slug,
            'name' => $manifest->name,
            'type' => $manifest->type->value,
            'version' => $manifest->version,
            'provider' => $manifest->provider,
            'path' => $this->catalogue->relativePathFor($slug) ?? $slug,
            'state' => ModuleState::Installed->value,
            'installed_at' => CarbonImmutable::now(),
        ]);

        Audit::action('modules.installed')
            ->by($actor)
            ->on($record)
            ->withMetadata([
                'version' => $manifest->version,
                'type' => $manifest->type->value,
                'sdk' => (string) $manifest->sdk,
            ])
            ->write();

        return $record;
    }
}
