<?php

declare(strict_types=1);

namespace App\Domain\Automation;

/**
 * Everything this installation does on its own.
 *
 * A closed list rather than a string, for the same reason
 * `NotificationEvent` is one: an operator has to be shown what can run, and
 * a screen cannot list something that only exists as a literal inside a
 * console command.
 */
enum AutomationTask: string
{
    case Renewals = 'renewals';
    case Dunning = 'dunning';
    case Overdue = 'overdue';
    case DomainExpiry = 'domain-expiry';
    case Retries = 'retries';
    case Sync = 'sync';
    case Webhooks = 'webhooks';
    case Cleanup = 'cleanup';

    /**
     * Keeping the Resource Graph in step with the rows it describes.
     *
     * A task rather than a set of events, because it asks a question about
     * state: which servers have no node, which nodes have no server. A
     * scheduler that was down all night catches up on the next run instead
     * of leaving an operator with an inventory permanently missing a day
     * (ADR 0031, ADR 0043).
     */
    case Resources = 'resources';

    /**
     * Asking every monitoring adapter what it currently knows.
     *
     * The other half of `Resources`: one task puts the resources in the graph
     * and this one puts numbers against them. Separate because they fail
     * differently — a projection failing means the inventory is stale, and a
     * collection failing means somebody else's system is down.
     */
    case Telemetry = 'telemetry';

    /**
     * Asking every adapter whether the thing on the other end is still there.
     *
     * Separate from `Telemetry` because they answer different questions and
     * fail differently: collection failing means a source is quiet, and
     * health failing means it is gone. Phase A left this as a button and said
     * why — a sweep polling twenty devices before anybody had configured a
     * timeout is a denial of service against your own operator — so the
     * pacing comes from the limits each adapter declares.
     */
    case AdapterHealth = 'adapter-health';

    /**
     * Telling the vendor this installation is still here.
     *
     * A task rather than a middleware or a boot hook, because it is a remote
     * call: doing it in a request would put a vendor's latency in front of a
     * customer, and doing it on boot would do it thousands of times a day.
     */
    case Licence = 'licence';

    public function labelKey(): string
    {
        return 'automation.tasks.'.str_replace('-', '_', $this->value).'.label';
    }

    public function descriptionKey(): string
    {
        return 'automation.tasks.'.str_replace('-', '_', $this->value).'.description';
    }

    public function command(): string
    {
        return 'platform:run '.$this->value;
    }

    /**
     * How often the scheduler runs it, in minutes.
     *
     * Stated here rather than only in `routes/console.php` so the admin
     * screen can say when a task is next expected and the health check can
     * notice one that has not run.
     */
    public function intervalMinutes(): int
    {
        return match ($this) {
            self::Retries, self::Webhooks => 5,
            // Hourly. The heartbeat itself only speaks to the vendor when the
            // state says it is due — half way to the deadline — so this is how
            // often it *checks*, not how often it calls.
            self::Licence => 60,
            self::Sync => 360,
            // Hourly. Cheap — it reads three tables and writes only what
            // changed — and an operator who has just added a server should not
            // have to wait until tomorrow to see it on the graph.
            self::Resources => 60,
            // Five minutes, which is what a monitoring cadence looks like. Each
            // adapter declares its own rate limits and the run respects them, so
            // this is how often the platform *asks*, not how hard it pushes.
            self::Telemetry => 5,
            // Five minutes as well, and for the same reason it is not one:
            // health is not telemetry, and a device that went down forty
            // seconds ago is found by whatever was talking to it.
            self::AdapterHealth => 5,
            self::Renewals, self::Dunning, self::Overdue, self::DomainExpiry, self::Cleanup => 1440,
        };
    }
}
