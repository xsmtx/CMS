<?php

declare(strict_types=1);

namespace App\Infrastructure\Health\Checks;

use App\Domain\Health\Contracts\HealthCheck;
use App\Domain\Health\HealthReport;
use Illuminate\Contracts\Queue\Queue;
use Illuminate\Support\Facades\Queue as QueueFacade;

/**
 * How much work is waiting.
 *
 * A queue with a thousand jobs on it is the platform's clearest early
 * warning: nothing has failed yet, and everything is about to be late. That
 * is what `degraded` is for, and collapsing it into ok or failing is why
 * nobody noticed.
 *
 * Every named queue is counted separately, because a healthy `default` says
 * nothing about a `provisioning` queue nobody is watching — the exact
 * failure Phase 6 hit.
 */
final readonly class QueueCheck implements HealthCheck
{
    public function key(): string
    {
        return 'queue';
    }

    public function run(): HealthReport
    {
        $connection = QueueFacade::connection();

        $measurements = [];
        $total = 0;

        foreach ($this->queues() as $queue) {
            $size = $connection instanceof Queue ? $connection->size($queue) : 0;
            $measurements[$queue] = $size;
            $total += $size;
        }

        $warning = (int) config('platform.health.queue_depth_warning', 100);
        $critical = (int) config('platform.health.queue_depth_critical', 1000);

        if ($total >= $critical) {
            return HealthReport::failing($this->key(), (string) __('health.queue.backed_up'), $measurements);
        }

        if ($total >= $warning) {
            return HealthReport::degraded($this->key(), (string) __('health.queue.busy'), $measurements);
        }

        return HealthReport::ok($this->key(), $measurements);
    }

    /**
     * @return list<string>
     */
    private function queues(): array
    {
        /** @var list<string> $queues */
        $queues = config('horizon.defaults.supervisor-1.queue', ['default']);

        return $queues === [] ? ['default'] : array_values($queues);
    }
}
