<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Infrastructure\Modules\Models\ModuleRecord;
use App\Infrastructure\Modules\ModuleCatalogue;
use Illuminate\Console\Command;

/**
 * What is on disk, and what this installation has decided about it.
 *
 * Two questions that are usually asked together and are genuinely
 * different: a directory somebody dropped in is not an installed module,
 * and an installed module is not a running one. A package that is on disk
 * with no row is the normal state of something just unpacked.
 *
 * Reads only. This command never loads a module's code
 * ([ADR 0038](../../../docs/adr/0038-a-module-may-execute.md)) — running
 * `module:list` on a box is not a decision about anything.
 */
final class ListModulesCommand extends Command
{
    protected $signature = 'module:list {--state= : Only modules in this state}';

    protected $description = 'List modules on disk and what this installation has decided about them';

    public function handle(ModuleCatalogue $catalogue): int
    {
        $manifests = $catalogue->all();
        $records = ModuleRecord::query()->get()->keyBy('slug');
        $state = $this->option('state');

        $rows = [];

        foreach ($manifests as $slug => $manifest) {
            $record = $records->get($slug);
            $actual = $record instanceof ModuleRecord ? $record->state->value : 'on-disk';

            if (is_string($state) && $state !== '' && $state !== $actual) {
                continue;
            }

            $rows[] = [
                $slug,
                $manifest->name,
                $manifest->type->value,
                $manifest->version,
                (string) $manifest->sdk,
                $actual,
                $record?->failure_reason === null ? '' : mb_substr($record->failure_reason, 0, 48),
            ];
        }

        // A row with no manifest is a module whose directory has gone. It
        // is the most important line on this screen and the easiest one to
        // leave out, because the loop above is over what is on disk.
        foreach ($records as $slug => $record) {
            if (isset($manifests[$slug])) {
                continue;
            }

            if (is_string($state) && $state !== '' && $state !== $record->state->value) {
                continue;
            }

            $rows[] = [
                $slug,
                $record->name,
                $record->type->value,
                $record->version,
                '—',
                $record->state->value,
                'missing from disk',
            ];
        }

        if ($rows === []) {
            $this->info('No modules.');

            return self::SUCCESS;
        }

        usort($rows, static fn (array $a, array $b): int => strcmp((string) $a[0], (string) $b[0]));

        $this->table(['Slug', 'Name', 'Type', 'Version', 'SDK', 'State', 'Note'], $rows);

        $this->line('');
        $this->line(sprintf(
            '  %d on disk, %d recorded. Enabling is the moment a module runs.',
            count($manifests),
            $records->count(),
        ));

        return self::SUCCESS;
    }
}
