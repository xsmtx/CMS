<?php

declare(strict_types=1);

namespace App\Application\Access;

use App\Domain\Access\PermissionDefinition;
use App\Domain\Access\PermissionRegistry;
use App\Infrastructure\Access\Models\Permission;
use App\Infrastructure\Access\PermissionCache;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Mirrors the code-declared permission registry into the database.
 *
 * Idempotent by construction: running it twice in a row is a no-op, which
 * matters because it runs on every deployment and on every module install.
 */
final readonly class SyncPermissions
{
    public function __construct(private PermissionCache $cache) {}

    public function handle(PermissionRegistry $registry): PermissionSyncResult
    {
        /** @var PermissionSyncResult $result */
        $result = DB::transaction(function () use ($registry): PermissionSyncResult {
            $created = [];
            $updated = [];
            $restored = [];

            /** @var array<string, Permission> $existing */
            $existing = Permission::query()->get()->keyBy('slug')->all();

            foreach ($registry->all() as $slug => $definition) {
                $record = $existing[$slug] ?? null;

                if ($record === null) {
                    Permission::query()->create($this->attributes($definition));
                    $created[] = $slug;

                    continue;
                }

                if ($record->isOrphaned()) {
                    $restored[] = $slug;
                }

                $record->fill($this->attributes($definition));

                if ($record->isDirty()) {
                    $record->save();

                    if (! in_array($slug, $restored, strict: true)) {
                        $updated[] = $slug;
                    }
                }
            }

            $orphaned = $this->orphanMissing($existing, $registry->slugs());

            return new PermissionSyncResult($created, $updated, $orphaned, $restored);
        });

        if ($result->changedAnything()) {
            $this->cache->flush();
        }

        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    private function attributes(PermissionDefinition $definition): array
    {
        return [
            'slug' => $definition->slug,
            'group' => $definition->group,
            'scope' => $definition->scope->value,
            'is_high_risk' => $definition->highRisk,
            'module' => $definition->module,
            'orphaned_at' => null,
        ];
    }

    /**
     * A permission no longer declared in code is kept and flagged. Deleting
     * it would cascade away the role grants that explain historical
     * decisions.
     *
     * @param  array<string, Permission>  $existing
     * @param  list<string>  $declared
     * @return list<string>
     */
    private function orphanMissing(array $existing, array $declared): array
    {
        $orphaned = [];
        $now = CarbonImmutable::now();

        foreach ($existing as $slug => $record) {
            if (in_array($slug, $declared, strict: true) || $record->isOrphaned()) {
                continue;
            }

            $record->forceFill(['orphaned_at' => $now])->save();
            $orphaned[] = $slug;
        }

        return $orphaned;
    }
}
