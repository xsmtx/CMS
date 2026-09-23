<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Infrastructure\Platform\Models\PlatformState;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Writes down that the scheduler is alive.
 *
 * The only way to notice a scheduler container that died. Everything else
 * on the health page reports on something this application actively does;
 * a dead scheduler is the absence of activity, and absence cannot report
 * itself.
 *
 * In a table rather than the cache: a heartbeat that disappears when Redis
 * restarts reports the scheduler dead after every deploy, and an operator
 * who has dismissed three false alarms will dismiss the real one.
 */
final class RecordSchedulerHeartbeatCommand extends Command
{
    protected $signature = 'platform:heartbeat';

    protected $description = 'Record that the scheduler ran';

    public function handle(): int
    {
        PlatformState::query()->updateOrCreate(
            ['key' => PlatformState::SCHEDULER_HEARTBEAT],
            ['value' => ['at' => CarbonImmutable::now()->toIso8601String()], 'updated_at' => CarbonImmutable::now()],
        );

        return self::SUCCESS;
    }
}
