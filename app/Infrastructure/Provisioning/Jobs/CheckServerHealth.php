<?php

declare(strict_types=1);

namespace App\Infrastructure\Provisioning\Jobs;

use App\Domain\Provisioning\ConnectionResult;
use App\Domain\Provisioning\Contracts\ProvisioningModule;
use App\Infrastructure\Provisioning\Models\Server;
use App\Infrastructure\Provisioning\ModuleRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Asks a node whether it is still there.
 *
 * Records what it found and nothing else. It does not take a node out of
 * rotation: a control panel that failed one check at 3am is usually a
 * control panel that was restarting, and a platform that reshuffles its
 * fleet on that basis causes more incidents than it prevents. Phase 9's
 * operations centre decides what to do with a run of failures.
 */
final class CheckServerHealth implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $uniqueFor = 300;

    public int $tries = 1;

    public function __construct(public readonly string $serverId)
    {
        $this->onQueue('provisioning');
    }

    public function uniqueId(): string
    {
        return $this->serverId;
    }

    public function handle(ModuleRegistry $modules): void
    {
        $server = Server::query()
            ->withoutGlobalScope('organization')
            ->find($this->serverId);

        if (! $server instanceof Server) {
            return;
        }

        $module = $modules->find($server->module);

        if (! $module instanceof ProvisioningModule || ! $module->capabilities()->testConnection) {
            $server->forceFill([
                'health' => 'unknown',
                'health_message' => (string) __('provisioning.health.not_checkable'),
                'health_checked_at' => CarbonImmutable::now(),
            ])->save();

            return;
        }

        $result = $module->testConnection($server->connection());

        $server->forceFill([
            'health' => $result->reachable ? 'healthy' : 'unreachable',
            'health_message' => $this->messageFor($result),
            'health_checked_at' => CarbonImmutable::now(),
        ])->save();
    }

    private function messageFor(ConnectionResult $result): ?string
    {
        if (! $result->reachable) {
            return $result->message;
        }

        return $result->version === null
            ? null
            : $result->version.($result->durationMs === null ? '' : ' · '.$result->durationMs.'ms');
    }
}
