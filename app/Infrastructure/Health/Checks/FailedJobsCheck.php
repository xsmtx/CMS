<?php

declare(strict_types=1);

namespace App\Infrastructure\Health\Checks;

use App\Domain\Health\Contracts\HealthCheck;
use App\Domain\Health\HealthReport;
use Illuminate\Support\Facades\DB;

/**
 * Jobs that used up every try.
 *
 * One failed job is worth a look, which is why the warning threshold
 * defaults to one rather than to a round number. A platform that provisions
 * hosting accounts has no acceptable background failure rate; the number is
 * configurable for installations that disagree, not because we do.
 */
final readonly class FailedJobsCheck implements HealthCheck
{
    public function key(): string
    {
        return 'failed_jobs';
    }

    public function run(): HealthReport
    {
        $count = (int) DB::table('failed_jobs')->count();

        $warning = (int) config('platform.health.failed_jobs_warning', 1);
        $critical = (int) config('platform.health.failed_jobs_critical', 25);

        if ($count >= $critical) {
            return HealthReport::failing($this->key(), (string) __('health.jobs.many_failed'), ['failed' => $count]);
        }

        if ($count >= $warning) {
            return HealthReport::degraded($this->key(), (string) __('health.jobs.some_failed'), ['failed' => $count]);
        }

        return HealthReport::ok($this->key(), ['failed' => $count]);
    }
}
