<?php

declare(strict_types=1);

namespace App\Domain\Reliability;

/**
 * What a rule asks a question about.
 *
 * A closed list, unlike `ResourceKind`, and for the same reason `Relation` is
 * closed: the evaluator has to know how to **read** each one, and a subject
 * nobody in core can read is a rule that silently never fires. A screen
 * offering it would be a screen promising an alert that cannot happen.
 *
 * Every member is something this installation already knows about without any
 * adapter being configured — which is the point of the phase. An operator who
 * installs no modules at all still gets told when the queue backs up, when a
 * provisioning job fails or when the scheduler stops.
 *
 * `Metric` is the one that needs telemetry, and it is the one that is empty on
 * an installation with no monitoring adapter. That is honest rather than
 * broken: the rule is written, nothing matches it, and the alert list says
 * nothing is wrong because nothing is being measured.
 */
enum AlertSubject: string
{
    /**
     * A reading about a resource in the graph, by `MetricKind`.
     *
     * The only member that reaches outside this installation, and the only one
     * that can be silent because nothing is reporting.
     */
    case Metric = 'metric';

    /** One of the registered health checks, by its key. */
    case HealthCheck = 'health_check';

    /** An adapter's own `health`, by adapter key. */
    case AdapterHealth = 'adapter_health';

    /** An automation task that failed or has not run, by task value. */
    case AutomationRun = 'automation_run';

    /** Operations that ended in `failed` or `manual_intervention`. */
    case FailedOperation = 'failed_operation';

    /**
     * A resource whose capacity forecast says it runs out soon.
     *
     * Phase B's `CapacityForecast`, which declines to answer more often than
     * it answers — so a rule on this is quiet by construction rather than by
     * threshold.
     */
    case Capacity = 'capacity';

    /**
     * Whether this subject needs a target naming which thing.
     *
     * A metric rule is about a measurement across everything that reports it;
     * a health-check rule is about one named check. The form asks for the
     * target only where there is one to ask for.
     */
    public function needsTarget(): bool
    {
        return match ($this) {
            self::HealthCheck, self::AdapterHealth, self::AutomationRun => true,
            self::Metric, self::FailedOperation, self::Capacity => false,
        };
    }

    /**
     * Whether a threshold is a number.
     *
     * A metric and a capacity forecast compare against one; a health check is
     * a state and an automation run is a fact. A form that asked for "90" next
     * to "the scheduler has not run" would be a form nobody could fill in.
     */
    public function isNumeric(): bool
    {
        return match ($this) {
            self::Metric, self::Capacity => true,
            self::HealthCheck, self::AdapterHealth, self::AutomationRun, self::FailedOperation => false,
        };
    }

    public function labelKey(): string
    {
        return 'reliability.subjects.'.$this->value;
    }
}
