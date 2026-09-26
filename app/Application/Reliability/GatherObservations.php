<?php

declare(strict_types=1);

namespace App\Application\Reliability;

use App\Application\Health\HealthChecks;
use App\Application\Infrastructure\CapacityOutlooks;
use App\Domain\Automation\AutomationTask;
use App\Domain\Automation\RunStatus;
use App\Domain\Health\HealthState;
use App\Domain\Infrastructure\MetricKind;
use App\Domain\Infrastructure\MetricUnit;
use App\Domain\Operations\OperationState;
use App\Domain\Reliability\AlertSubject;
use App\Domain\Reliability\Observation;
use App\Infrastructure\Automation\Models\AutomationRunRecord;
use App\Infrastructure\Operations\Models\Operation;
use App\Infrastructure\Resources\Models\ResourceAdapter;
use App\Infrastructure\Resources\Models\ResourceMetric;
use App\Infrastructure\Resources\Models\ResourceNode;
use Illuminate\Support\Facades\Lang;
use Throwable;

/**
 * What the installation currently knows, in the shape a rule can be asked
 * about.
 *
 * **No adapter is required for any of this.** Every subject but `Metric`
 * reads a table this platform has had since handoff #1 — the health checks,
 * the adapter rows, the automation runs, the operations. An operator who
 * installs nothing at all still gets told when the queue backs up, when a
 * provisioning job fails and when the scheduler stops, which is the whole
 * point of alerting living in core.
 *
 * **Nothing here decides anything.** An observation says what is true; the
 * rule says whether that is bad enough to raise. Two classes rather than one,
 * because the day a second thing wants to read "what is currently true" — a
 * status page, a dashboard, the mobile app — it must not have to go through
 * the alerting rules to get it.
 *
 * **A subject with nothing to report answers an empty list**, which the
 * evaluator reads as "nothing to raise and nothing to clear". That is the
 * correct answer for an installation with no monitoring adapter: not an
 * alert, not an error, just silence about a thing nobody is measuring.
 */
final readonly class GatherObservations
{
    /**
     * How far back an automation run counts as "has run".
     *
     * A task's own interval times three, so a five-minute task is late after
     * fifteen and an hourly one after three hours. Multiplied rather than
     * fixed, because a fixed number would make the hourly tasks permanently
     * late or the five-minute ones never late.
     */
    private const int LatenessFactor = 3;

    public function __construct(
        private HealthChecks $checks,
        private CapacityOutlooks $capacity,
    ) {}

    /**
     * @return list<Observation>
     */
    public function for(AlertSubject $subject, ?string $target, ?string $organizationId = null): array
    {
        return match ($subject) {
            AlertSubject::Metric => $this->metrics($target),
            AlertSubject::HealthCheck => $this->healthChecks($target),
            AlertSubject::AdapterHealth => $this->adapters($target),
            AlertSubject::AutomationRun => $this->automationRuns($target),
            AlertSubject::FailedOperation => $this->failedOperations(),
            AlertSubject::Capacity => $this->capacityRuns($organizationId),
        };
    }

    /**
     * Every current reading of one metric.
     *
     * Stale readings are **skipped rather than alerted on**, and that is the
     * subtle one: a machine that stopped reporting is a monitoring problem,
     * not a disk that is 94% full, and raising the last known value for ever
     * would be this platform asserting something it no longer knows. The
     * Telemetry screen is where staleness is somebody's problem.
     *
     * @return list<Observation>
     */
    private function metrics(?string $target): array
    {
        $kind = $target === null ? null : MetricKind::tryFrom($target);

        if (! $kind instanceof MetricKind) {
            return [];
        }

        $observations = [];

        $rows = ResourceMetric::query()
            ->with('node')
            ->where('metric', $kind->value)
            ->get();

        foreach ($rows as $row) {
            if ($row->isStale()) {
                continue;
            }

            $node = $row->node;

            // A reading whose node has been retired since. Skipped rather
            // than alerted on: a rule about a machine that no longer exists
            // is a rule nobody can act on.
            if (! $node instanceof ResourceNode) {
                continue;
            }

            $observations[] = new Observation(
                key: $node->node_key,
                label: $node->label,
                // The rule decides. A reading is never bad by itself.
                bad: true,
                observed: $this->written($row->unit, (float) $row->value),
                value: (float) $row->value,
            );
        }

        return $observations;
    }

    /**
     * @return list<Observation>
     */
    private function healthChecks(?string $target): array
    {
        $observations = [];

        foreach ($this->checks->run() as $report) {
            if ($target !== null && $report->key !== $target) {
                continue;
            }

            $observations[] = new Observation(
                key: $report->key,
                label: $this->worded('health.checks.'.$report->key, $report->key),
                bad: $report->state !== HealthState::Ok,
                observed: (string) __($report->state->labelKey()),
            );
        }

        return $observations;
    }

    /**
     * @return list<Observation>
     */
    private function adapters(?string $target): array
    {
        $observations = [];

        $rows = ResourceAdapter::query()
            ->where('enabled', true)
            ->when($target !== null, static fn ($query) => $query->where('adapter_key', $target))
            ->get();

        foreach ($rows as $row) {
            $state = $row->healthState();

            $observations[] = new Observation(
                key: $row->adapter_key,
                label: $row->name,
                // An adapter nothing has checked yet is not a failure. It is
                // the ordinary state of one that was enabled a minute ago, and
                // raising on it would page somebody for installing a module.
                bad: $state instanceof HealthState && $state !== HealthState::Ok,
                observed: $state instanceof HealthState
                    ? (string) __($state->labelKey())
                    : (string) __('health.states.unknown'),
            );
        }

        return $observations;
    }

    /**
     * A task that failed, or that has not run in three of its own intervals.
     *
     * Both in one subject because an operator cares about the same thing
     * either way: the sweep is not doing its job. Which of the two it is
     * shows in `observed`.
     *
     * @return list<Observation>
     */
    private function automationRuns(?string $target): array
    {
        $observations = [];

        foreach (AutomationTask::cases() as $task) {
            if ($target !== null && $task->value !== $target) {
                continue;
            }

            $latest = AutomationRunRecord::query()
                ->where('task', $task->value)
                ->latest('started_at')
                ->first();

            $label = $this->worded($task->labelKey(), $task->value);

            if (! $latest instanceof AutomationRunRecord) {
                // Never run. Not an alert on a fresh installation, where
                // nothing has run yet by definition — the scheduler heartbeat
                // is the check that catches a scheduler that never starts.
                continue;
            }

            $late = $latest->started_at->addMinutes(
                $task->intervalMinutes() * self::LatenessFactor,
            )->isPast();

            $failed = $latest->status === RunStatus::Failed || $latest->failed > 0;

            $observations[] = new Observation(
                key: $task->value,
                label: $label,
                bad: $late || $failed,
                observed: $late
                    ? (string) __('reliability.observed.late', [
                        'at' => $latest->started_at->toDateTimeString(),
                    ])
                    : (string) __('reliability.observed.failed_items', ['count' => $latest->failed]),
            );
        }

        return $observations;
    }

    /**
     * Operations that ended badly and nobody has resolved.
     *
     * One observation per operation rather than a count, so the alert names
     * the thing: "Terminate service for Acme" is actionable and "7 failed
     * operations" is a number somebody has to go and look up.
     *
     * @return list<Observation>
     */
    private function failedOperations(): array
    {
        $observations = [];

        $rows = Operation::query()
            ->whereIn('state', [
                OperationState::Failed->value,
                OperationState::ManualIntervention->value,
            ])
            ->whereNull('resolved_at')
            ->latest('finished_at')
            ->limit(200)
            ->get();

        foreach ($rows as $row) {
            $observations[] = new Observation(
                key: $row->id,
                label: $row->subject_label ?? (string) __($row->type->labelKey()),
                bad: true,
                observed: (string) __($row->state->labelKey()),
            );
        }

        return $observations;
    }

    /**
     * Resources the forecast says run out soon.
     *
     * `CapacityForecast` declines to answer more often than it answers —
     * fewer than seven days of history is a week rather than a trend, and a
     * flat line is not the disk to worry about — so a rule on this is quiet
     * by construction rather than by threshold. The value is **days
     * remaining**, and the comparison an operator writes is `Below 14`.
     *
     * @return list<Observation>
     */
    private function capacityRuns(?string $organizationId): array
    {
        if ($organizationId === null) {
            return [];
        }

        try {
            $rows = $this->capacity->soonest($organizationId, limit: 200);
        } catch (Throwable) {
            // A forecast that cannot be computed is silence, not an alert.
            // Alerting on the alerting is how a platform pages somebody at
            // three in the morning about itself.
            return [];
        }

        $observations = [];

        foreach ($rows as $row) {
            $days = $row['outlook']->daysRemaining();

            if ($days === null) {
                continue;
            }

            $observations[] = new Observation(
                key: $row['node']->node_key.'/'.$row['metric']->value,
                // `infrastructure.metrics.<value>` rather than a `labelKey()`:
                // `MetricKind` has none, and the value has a dot in it, so a
                // key built from it would ask the translator to walk two
                // levels and come back with the path — the permission-slug
                // trap through a third door.
                label: $row['node']->label.' — '
                    .__('infrastructure.metrics.'.$row['metric']->value),
                bad: true,
                observed: (string) __('reliability.observed.days_left', ['days' => $days]),
                value: (float) $days,
            );
        }

        return $observations;
    }

    /**
     * A reading in the words a screen uses.
     *
     * From the **row's** unit rather than the kind's canonical one. They are
     * the same whenever `RecordSamples` wrote the row — it refuses a unit from
     * the wrong dimension — but reading the kind would be reading a second
     * source about the same number, and the one that is actually stored is
     * the one an operator is being shown.
     */
    private function written(?MetricUnit $unit, float $value): string
    {
        return match ($unit?->value) {
            'ratio' => number_format($value * 100, 1).'%',
            'percent' => number_format($value, 1).'%',
            'bytes' => $this->scaled($value, ['B', 'kB', 'MB', 'GB', 'TB']),
            default => (string) round($value, 2),
        };
    }

    /**
     * @param  list<string>  $units
     */
    private function scaled(float $value, array $units): string
    {
        $index = 0;

        while ($value >= 1000 && $index < count($units) - 1) {
            $value /= 1000;
            $index++;
        }

        return number_format($value, $index === 0 ? 0 : 1).' '.$units[$index];
    }

    /**
     * Wording, falling back to the key rather than to a translation path.
     *
     * `health.checks.whatever` printed at an operator reads as a bug;
     * `whatever` reads as a check nobody has named, which is the truth.
     */
    private function worded(string $key, string $fallback): string
    {
        return Lang::has($key) ? (string) __($key) : $fallback;
    }
}
