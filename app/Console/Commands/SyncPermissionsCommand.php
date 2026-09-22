<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Application\Access\SyncPermissions;
use App\Domain\Access\PermissionRegistry;
use App\Support\Audit\Facades\Audit;
use Illuminate\Console\Command;

/**
 * Mirrors declared permissions into the database. Runs on every deploy.
 */
final class SyncPermissionsCommand extends Command
{
    protected $signature = 'platform:permissions:sync';

    protected $description = 'Synchronise code-declared permissions into the database';

    public function handle(SyncPermissions $sync, PermissionRegistry $registry): int
    {
        $result = $sync->handle($registry);

        foreach ($result->counts() as $label => $count) {
            $this->line(sprintf('  %-9s %d', $label, $count));
        }

        if (! $result->changedAnything()) {
            $this->info('Permissions already in sync.');

            return self::SUCCESS;
        }

        Audit::action('access.permissions.synced')
            ->bySystem('platform:permissions:sync')
            ->withMetadata([
                'created' => $result->created,
                'updated' => $result->updated,
                'orphaned' => $result->orphaned,
                'restored' => $result->restored,
            ])
            ->write();

        $this->info('Permissions synchronised.');

        return self::SUCCESS;
    }
}
