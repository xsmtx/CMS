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
     * How many days a deployed certificate has left (§8).
     *
     * **The threshold is the operator's**, which is the whole reason this is
     * a rule rather than a constant. Thirty days is right for a business that
     * renews by hand and absurd for one on ACME with a fortnight's lifetime;
     * core shipping a number would be core deciding when somebody should care.
     *
     * It is empty on an installation with no certificate adapter, like
     * `Metric` — honest rather than broken.
     */
    case CertificateExpiry = 'certificate_expiry';

    /**
     * How many days one of our addresses has been on a blocklist (§13).
     *
     * **Days rather than a count of listings**, because the count is not the
     * question. One address on four lists is one problem, and the thing that
     * decides whether anybody should be woken is how long it has been true:
     * a listing that lifts itself within the hour is noise, and one that is
     * still there on the third morning is somebody's mail not arriving.
     *
     * The threshold is the operator's, like the certificate one. A business
     * sending its own newsletters and one running four hundred shared hosting
     * accounts do not agree about when this matters, and core shipping a
     * number would be core deciding for both.
     */
    case ReputationListing = 'reputation_listing';

    /**
     * How old the last good backup of something is (§12).
     *
     * **The age of the last good copy, never the last outcome.** A job that
     * failed last night is a warning; a job that has succeeded every night
     * for a month against a resource deleted three weeks ago is a lie, and a
     * green tick beside it is worse than a red cross.
     *
     * A protection that has never had a good copy produces no observation at
     * all. A resource added to a job this afternoon has not failed, and an
     * age invented for it would be a number somebody acts on — the coverage
     * screen says “nothing yet” in words instead.
     *
     * The threshold is the operator's, like every other numeric subject: a
     * nightly schedule and a weekly one do not agree about when to worry.
     */
    case BackupAge = 'backup_age';

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
            self::Metric, self::FailedOperation, self::Capacity,
            // No target: a rule about expiry is about every certificate this
            // installation can see, not about one of them. An operator who
            // wanted one certificate watched would be writing a rule they
            // have to rewrite at every renewal.
            self::CertificateExpiry, self::ReputationListing, self::BackupAge => false,
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
            // Days remaining, which is a number an operator compares
            // against — "below 14" is the rule everybody writes.
            // Days listed is the same shape: “above 2” is the rule,
            // because how long it has been true is what decides whether
            // anybody should be woken for it.
            self::Metric, self::Capacity, self::CertificateExpiry,
            self::ReputationListing, self::BackupAge => true,
            self::HealthCheck, self::AdapterHealth, self::AutomationRun, self::FailedOperation => false,
        };
    }

    public function labelKey(): string
    {
        return 'reliability.subjects.'.$this->value;
    }
}
