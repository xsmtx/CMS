<?php

declare(strict_types=1);

namespace App\Infrastructure\Health\Checks;

use App\Domain\Health\Contracts\HealthCheck;
use App\Domain\Health\HealthReport;
use App\Infrastructure\Platform\Models\PlatformState;
use Carbon\CarbonImmutable;

/**
 * Is the scheduler still alive.
 *
 * The one check that reports on an absence, and therefore the one that
 * cannot be written any other way: a dead scheduler container produces no
 * error, no failed job and no log line. It simply stops, and six days later
 * somebody notices that nobody was invoiced.
 *
 * Never having seen a heartbeat is reported differently from having stopped
 * seeing one. A fresh installation has not run the scheduler yet, and
 * telling an operator their scheduler has died on the day they installed
 * the platform teaches them to ignore this line.
 */
final readonly class SchedulerCheck implements HealthCheck
{
    public function key(): string
    {
        return 'scheduler';
    }

    public function run(): HealthReport
    {
        $state = PlatformState::query()->find(PlatformState::SCHEDULER_HEARTBEAT);

        if ($state === null) {
            return HealthReport::degraded($this->key(), (string) __('health.scheduler.never_seen'));
        }

        $value = $state->value;
        $at = is_array($value) && isset($value['at']) && is_string($value['at'])
            ? CarbonImmutable::parse($value['at'])
            : null;

        if ($at === null) {
            return HealthReport::degraded($this->key(), (string) __('health.scheduler.never_seen'));
        }

        $minutes = (int) $at->diffInMinutes(CarbonImmutable::now());
        $stale = (int) config('platform.health.heartbeat_stale_minutes', 15);

        if ($minutes > $stale) {
            return HealthReport::failing(
                $this->key(),
                (string) __('health.scheduler.stale'),
                ['minutes_ago' => $minutes],
            );
        }

        return HealthReport::ok($this->key(), ['minutes_ago' => $minutes]);
    }
}
