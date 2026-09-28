<?php

declare(strict_types=1);

namespace App\Application\Reliability;

use App\Application\Health\HealthChecks;
use App\Application\Infrastructure\CapacityOutlooks;
use App\Application\Infrastructure\MetricNames;
use App\Domain\Automation\AutomationTask;
use App\Domain\Automation\RunStatus;
use App\Domain\Health\HealthState;
use App\Domain\Infrastructure\MetricKind;
use App\Domain\Infrastructure\MetricUnit;
use App\Domain\Operations\OperationState;
use App\Domain\Reliability\AlertSubject;
use App\Domain\Reliability\Observation;
use App\Infrastructure\Automation\Models\AutomationRunRecord;
use App\Infrastructure\Backup\Models\BackupProtection;
use App\Infrastructure\Operations\Models\Operation;
use App\Infrastructure\Resources\Models\ResourceAdapter;
use App\Infrastructure\Resources\Models\ResourceMetric;
use App\Infrastructure\Resources\Models\ResourceNode;
use App\Infrastructure\Security\Models\Certificate;
use App\Infrastructure\Security\Models\ReputationListing;
use Carbon\CarbonImmutable;
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
        private MetricNames $metrics,
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
            AlertSubject::CertificateExpiry => $this->certificates($organizationId),
            AlertSubject::ReputationListing => $this->listings($organizationId),
            AlertSubject::BackupAge => $this->protections($organizationId),
            AlertSubject::SiteUpdates => $this->siteUpdates($organizationId),
            AlertSubject::SiteVulnerability => $this->siteVulnerabilities($organizationId),
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
                // Through `MetricNames`, never `__()`: a metric's value has a
                // dot in it, so a key built from it asks the translator to walk
                // two levels and comes back with the path. The comment that
                // used to sit here described that trap exactly and the code
                // under it fell into it anyway — which is why the reader is a
                // class now rather than a rule somebody remembers.
                label: $row['node']->label.' — '.$this->metrics->label($row['metric']),
                bad: true,
                observed: (string) __('reliability.observed.days_left', ['days' => $days]),
                value: (float) $days,
            );
        }

        return $observations;
    }

    /**
     * How many days each live certificate has left (§8).
     *
     * Every one of them, every time: the rule decides which are worth an
     * alert, and a gatherer that pre-filtered by some number of its own would
     * be a second threshold nobody could see. Expired ones are included with
     * a negative figure, because "below 14" has to catch "minus 3" — a
     * certificate that lapsed last night is the one somebody most needs to
     * hear about.
     *
     * @return list<Observation>
     */
    private function certificates(?string $organizationId): array
    {
        if ($organizationId === null) {
            return [];
        }

        $observations = [];

        foreach (
            Certificate::query()
                ->where('organization_id', $organizationId)
                ->live()
                ->orderBy('not_after')
                ->limit(500)
                ->get() as $certificate
        ) {
            $days = $certificate->daysRemaining();

            $observations[] = new Observation(
                // The fingerprint, not the name: one name is served by four
                // certificates over a year, and an alert keyed on the name
                // would look like the same alert clearing and reopening at
                // every renewal.
                key: $certificate->fingerprint,
                label: $certificate->common_name,
                bad: true,
                observed: (string) __('reliability.observed.days_left', ['days' => $days]),
                value: (float) $days,
            );
        }

        return $observations;
    }

    /**
     * Every open blocklist listing, measured in days.
     *
     * **The row's own id is the key**, so an address that is listed, lifted
     * and listed again next month is two alerts rather than one that appeared
     * to flicker — they are two separate things to answer for.
     *
     * A lifted listing is simply absent, which is how the alert clears: the
     * evaluator closes what is no longer among the bad observations.
     *
     * @return list<Observation>
     */
    private function listings(?string $organizationId): array
    {
        if ($organizationId === null) {
            return [];
        }

        $now = CarbonImmutable::now();
        $observations = [];

        foreach (
            ReputationListing::query()
                ->where('organization_id', $organizationId)
                ->open()
                ->orderBy('first_seen_at')
                ->limit(500)
                ->get() as $listing
        ) {
            $days = (int) $listing->first_seen_at->diffInDays($now);

            $observations[] = new Observation(
                key: $listing->id,
                label: $listing->address.' — '.$listing->list,
                bad: true,
                observed: (string) __('reliability.observed.days_listed', ['days' => $days]),
                value: (float) $days,
            );
        }

        return $observations;
    }

    /**
     * How old each live protection's last good copy is, in days.
     *
     * **A protection with no good copy at all is skipped, not alerted on.** A
     * resource added to a job this afternoon has never had one and has not
     * failed; the coverage screen is where “nothing yet” belongs, in words.
     * It is the same rule staleness got in `metrics()` — this platform must
     * not assert a number it does not have.
     *
     * @return list<Observation>
     */
    private function protections(?string $organizationId): array
    {
        if ($organizationId === null) {
            return [];
        }

        $now = CarbonImmutable::now();
        $observations = [];

        foreach (
            BackupProtection::query()
                ->where('organization_id', $organizationId)
                ->live()
                ->whereNotNull('last_good_at')
                ->orderBy('last_good_at')
                ->limit(500)
                ->get() as $protection
        ) {
            $days = $protection->lastGoodAgeInDays($now);

            if ($days === null) {
                continue;
            }

            $observations[] = new Observation(
                key: $protection->id,
                label: $protection->resource_name,
                bad: true,
                observed: (string) __('reliability.observed.days_since_backup', ['days' => $days]),
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

    /**
     * How many things are out of date on each site (§18).
     *
     * **The core is counted with the components here, and only here.** The
     * node keeps them apart — `core_outdated` beside `outdated` — because
     * “WordPress 5.9 with nothing else behind” and “WordPress 6.6 with eleven
     * plugins behind” are different problems. A rule is one question written
     * once, though, and the question an operator means is how much is behind
     * on this site; the sentence on the alert says whether the core is part
     * of it.
     *
     * @return list<Observation>
     */
    private function siteUpdates(?string $organizationId): array
    {
        $observations = [];

        foreach ($this->siteNodes($organizationId) as $node) {
            $attributes = $node->attributes ?? [];

            $core = ($attributes['core_outdated'] ?? false) === true;
            $components = (int) ($attributes['outdated'] ?? 0);
            $behind = $components + ($core ? 1 : 0);

            $observations[] = new Observation(
                key: $node->node_key,
                label: $node->label,
                bad: true,
                observed: (string) __(
                    $core ? 'reliability.observed.behind_with_core' : 'reliability.observed.behind',
                    ['count' => $components],
                ),
                value: (float) $behind,
            );
        }

        return $observations;
    }

    /**
     * Sites with a component something has published an advisory against
     * (§18).
     *
     * **A site nothing looked at produces no observation**, which is the same
     * rule a stale metric gets: this platform must not assert that a site is
     * clean because no vulnerability database was configured. The node says
     * whether anything looked (`vulnerability_data`), and a site where
     * nothing did is skipped rather than reported as safe.
     *
     * @return list<Observation>
     */
    private function siteVulnerabilities(?string $organizationId): array
    {
        $observations = [];

        foreach ($this->siteNodes($organizationId) as $node) {
            $attributes = $node->attributes ?? [];

            if (($attributes['vulnerability_data'] ?? false) !== true) {
                continue;
            }

            $vulnerable = (int) ($attributes['vulnerable'] ?? 0);

            $observations[] = new Observation(
                key: $node->node_key,
                label: $node->label,
                bad: $vulnerable > 0,
                observed: (string) __('reliability.observed.vulnerable', ['count' => $vulnerable]),
                value: (float) $vulnerable,
            );
        }

        return $observations;
    }

    /**
     * Every site this organization can see, as the discovery run left them.
     *
     * @return list<ResourceNode>
     */
    private function siteNodes(?string $organizationId): array
    {
        if ($organizationId === null) {
            return [];
        }

        return array_values(ResourceNode::query()
            ->withoutGlobalScope('organization')
            ->where('organization_id', $organizationId)
            ->where('kind', 'site')
            ->whereNull('retired_at')
            ->orderBy('label')
            ->limit(2000)
            ->get()
            ->all());
    }
}
