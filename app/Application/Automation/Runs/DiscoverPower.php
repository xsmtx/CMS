<?php

declare(strict_types=1);

namespace App\Application\Automation\Runs;

use App\Application\Infrastructure\AdapterRegistry;
use App\Application\Infrastructure\RecordSamples;
use App\Application\Infrastructure\RegisteredAdapter;
use App\Application\Infrastructure\ResourceGraph;
use App\Domain\Automation\Contracts\AutomationRun;
use App\Domain\Automation\ItemOutcome;
use App\Domain\Automation\RunItem;
use App\Domain\Automation\RunSummary;
use App\Domain\Infrastructure\Capability;
use App\Domain\Infrastructure\Contracts\EnvironmentProvider;
use App\Domain\Infrastructure\Contracts\PowerProvider;
use App\Domain\Infrastructure\Contracts\UpsProvider;
use App\Domain\Infrastructure\MetricKind;
use App\Domain\Infrastructure\MetricSample;
use App\Domain\Infrastructure\Power\EnvironmentReading;
use App\Domain\Infrastructure\Power\PowerOutlet;
use App\Domain\Infrastructure\Power\SensorKind;
use App\Domain\Infrastructure\Power\UpsStatus;
use App\Domain\Infrastructure\Relation;
use App\Domain\Infrastructure\SampleBatch;
use App\Domain\Organizations\OrganizationType;
use App\Infrastructure\Organizations\Models\Organization;
use App\Infrastructure\Resources\Models\ResourceAdapter;
use App\Infrastructure\Resources\Models\ResourceNode;
use App\Support\Logging\SecretRedactor;
use App\Support\Organizations\OrganizationContext;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * Asks every PDU, UPS and rack sensor what it sees, and writes the power path
 * into the graph (§11).
 *
 * **`Relation::Powers` has existed since Phase A and has never been used.**
 * This is what it was for. The edge goes from the **outlet** to the device,
 * not from the PDU: two devices on one PDU are usually on different breakers,
 * and A-versus-B redundancy is the only question anybody asks of a power
 * diagram.
 *
 * **An outlet whose label matches no node keeps the name and gets no edge.** A
 * socket labelled `web-3` that this platform has never heard of is a true and
 * useful thing to see; inventing a node for it would put hardware into the
 * graph on the word of a sticker somebody typed into a PDU.
 *
 * **A leak, smoke or a door is an attribute, never a metric.** A state stored
 * as 1.0 is a chart nobody can read and an alert nobody can word;
 * `SensorKind::isReading()` is the rule and this is its only caller.
 *
 * One adapter often answers all three contracts — a rack sensor hangs off the
 * same NMC as the PDU — which is the case `AdapterArea` was split for.
 *
 * Every fifteen minutes. A room warms slowly and an outlet's load does not,
 * but a UPS on battery is the most urgent thing in this family and an hour is
 * too long to find out.
 */
final readonly class DiscoverPower implements AutomationRun
{
    private const string PduKind = 'pdu';

    private const string OutletKind = 'pdu_outlet';

    private const string UpsKind = 'ups';

    // Not `SensorKind`: the enum of that name is imported here, and a
    // constant that shadows a class is legal PHP and a sentence a
    // reader has to parse twice.
    private const string SensorNodeKind = 'environment_sensor';

    public function __construct(
        private OrganizationContext $organizations,
        private AdapterRegistry $registry,
        private ResourceGraph $graph,
        private RecordSamples $samples,
        private SecretRedactor $redactor,
    ) {}

    public function handle(): RunSummary
    {
        return $this->organizations->withoutBoundary(function (): RunSummary {
            $summary = new RunSummary;

            foreach ($this->providerOrganizationIds() as $organizationId) {
                foreach ($this->registry->all($organizationId) as $registered) {
                    $summary = $this->discoverFrom($summary, $organizationId, $registered);
                }
            }

            return $summary;
        });
    }

    private function discoverFrom(
        RunSummary $summary,
        string $organizationId,
        RegisteredAdapter $registered,
    ): RunSummary {
        $adapter = $registered->adapter();

        $isPower = $adapter instanceof PowerProvider;
        $isUps = $adapter instanceof UpsProvider;
        $isEnvironment = $adapter instanceof EnvironmentProvider;

        if (! $isPower && ! $isUps && ! $isEnvironment) {
            return $summary;
        }

        $permitted = $registered->permitted();

        // Each half is asked only where its own capability is enabled: an
        // operator who turned on the PDU read and not the UPS one gets the
        // first and not the second, rather than neither.
        $wantsPower = $isPower && $permitted->has(Capability::PduLoadRead);
        $wantsUps = $isUps && $permitted->has(Capability::UpsStatusRead);
        $wantsEnvironment = $isEnvironment && $permitted->has(Capability::PduLoadRead);

        if (! $wantsPower && ! $wantsUps && ! $wantsEnvironment) {
            return $summary->examining()->skipping();
        }

        $summary = $summary->examining();

        try {
            $written = $this->write(
                $organizationId,
                $registered,
                $wantsPower && $adapter instanceof PowerProvider ? $adapter->outlets() : [],
                $wantsUps && $adapter instanceof UpsProvider ? $adapter->units() : [],
                $wantsEnvironment && $adapter instanceof EnvironmentProvider ? $adapter->sensors() : [],
            );
        } catch (Throwable $exception) {
            return $summary->failing(new RunItem(
                ItemOutcome::Failed,
                ResourceAdapter::class,
                $registered->row->id,
                $registered->descriptor->name,
                $this->redactor->redactString($exception->getMessage()),
            ));
        }

        return $summary->changing(new RunItem(
            ItemOutcome::Changed,
            ResourceAdapter::class,
            $registered->row->id,
            $registered->descriptor->name,
            sprintf(
                '%d outlets (%d powering something), %d UPS, %d sensors',
                $written['outlets'],
                $written['powering'],
                $written['ups'],
                $written['sensors'],
            ),
        ));
    }

    /**
     * @param  list<PowerOutlet>  $outlets
     * @param  list<UpsStatus>  $units
     * @param  list<EnvironmentReading>  $sensors
     * @return array{outlets: int, powering: int, ups: int, sensors: int}
     */
    private function write(
        string $organizationId,
        RegisteredAdapter $registered,
        array $outlets,
        array $units,
        array $sensors,
    ): array {
        $source = substr('power:'.$registered->descriptor->key, 0, 48);
        $adapterKey = $registered->descriptor->key;
        $at = CarbonImmutable::now();

        $seen = [];
        /** @var list<MetricSample> $samples */
        $samples = [];
        $powering = 0;

        if ($outlets !== []) {
            // One node for the PDU itself, so its outlets have something to
            // hang from and a rack elevation has something to point at.
            $pdu = $this->graph->upsertNode(
                organizationId: $organizationId,
                kind: self::PduKind,
                nodeKey: $adapterKey,
                label: $registered->descriptor->name,
                source: $source,
                attributes: array_filter(['vendor' => $registered->descriptor->vendor]),
            );

            $seen[$pdu->node_key] = true;

            foreach ($outlets as $outlet) {
                $node = $this->graph->upsertNode(
                    organizationId: $organizationId,
                    kind: self::OutletKind,
                    nodeKey: $adapterKey.'/'.$outlet->key,
                    label: $outlet->name,
                    source: $source,
                    attributes: array_filter([
                        'feed' => $outlet->feed->value,
                        'breaker' => $outlet->breaker,
                        // Null is "the PDU did not say", never "off": a screen
                        // that drew an unreported outlet as switched off would
                        // send somebody to power on a machine already running.
                        'on' => $outlet->on,
                        // Kept even when it names nothing here.
                        'plugged_in' => $outlet->deviceKey,
                    ], static fn (mixed $value): bool => $value !== null),
                );

                $seen[$node->node_key] = true;

                $this->graph->attach($pdu, $node, Relation::Contains, source: $source);

                if ($outlet->watts !== null) {
                    $samples[] = new MetricSample($node->node_key, MetricKind::PowerDraw, $outlet->watts, $at);
                }

                if ($this->powerDevice($organizationId, $node, $outlet, $source)) {
                    $powering++;
                }
            }
        }

        foreach ($units as $unit) {
            $node = $this->graph->upsertNode(
                organizationId: $organizationId,
                kind: self::UpsKind,
                nodeKey: $adapterKey.'/ups/'.$unit->key,
                label: $unit->name,
                source: $source,
                attributes: array_filter([
                    // The field somebody is woken for, and separate from the
                    // charge: a unit at 100% that is on battery has lost its
                    // mains and looks perfectly healthy if only the
                    // percentage is read.
                    'on_battery' => $unit->onBattery,
                    // The unit's own words, not ours.
                    'alarm' => $unit->alarm,
                ], static fn (mixed $value): bool => $value !== null),
            );

            $seen[$node->node_key] = true;

            if ($unit->batteryPercent !== null) {
                $samples[] = new MetricSample(
                    $node->node_key,
                    MetricKind::BatteryCharge,
                    $unit->batteryPercent,
                    $at,
                );
            }

            if ($unit->runtimeSeconds !== null) {
                $samples[] = new MetricSample(
                    $node->node_key,
                    MetricKind::BatteryRuntime,
                    (float) $unit->runtimeSeconds,
                    $at,
                );
            }

            if ($unit->watts !== null) {
                $samples[] = new MetricSample($node->node_key, MetricKind::PowerDraw, $unit->watts, $at);
            }
        }

        foreach ($sensors as $sensor) {
            $node = $this->graph->upsertNode(
                organizationId: $organizationId,
                kind: self::SensorNodeKind,
                nodeKey: $adapterKey.'/sensor/'.$sensor->key,
                label: $sensor->name,
                source: $source,
                attributes: array_filter([
                    'sensor' => $sensor->kind->value,
                    'location' => $sensor->location,
                    // A state, never a metric: 1.0 on a chart is a reading
                    // nobody can read and an alert nobody can word.
                    'triggered' => $sensor->kind->isReading() ? null : $sensor->triggered,
                ], static fn (mixed $value): bool => $value !== null),
            );

            $seen[$node->node_key] = true;

            $metric = $this->metricFor($sensor->kind);

            if ($metric instanceof MetricKind && $sensor->value !== null) {
                $samples[] = new MetricSample($node->node_key, $metric, $sensor->value, $at);
            }
        }

        $this->retireDeparted($organizationId, $source, array_keys($seen));

        if ($samples !== []) {
            $this->samples->handle($organizationId, $source, new SampleBatch($samples), $at);
        }

        return [
            'outlets' => count($outlets),
            'powering' => $powering,
            'ups' => count($units),
            'sensors' => count($sensors),
        ];
    }

    /**
     * The device this socket feeds, where the graph already has it.
     *
     * `Relation::Powers`, from the outlet downward — which is what makes the
     * impact walk reach every machine behind one breaker, and what makes
     * "this device is on A only" a question with an answer.
     */
    private function powerDevice(
        string $organizationId,
        ResourceNode $outlet,
        PowerOutlet $descriptor,
        string $source,
    ): bool {
        if ($descriptor->deviceKey === null) {
            return false;
        }

        $device = ResourceNode::query()
            ->withoutGlobalScope('organization')
            ->where('organization_id', $organizationId)
            ->where('node_key', $descriptor->deviceKey)
            ->whereNull('retired_at')
            ->first();

        if (! $device instanceof ResourceNode) {
            return false;
        }

        $this->graph->attach($outlet, $device, Relation::Powers, source: $source);

        return true;
    }

    private function metricFor(SensorKind $kind): ?MetricKind
    {
        return match ($kind) {
            SensorKind::Temperature => MetricKind::Temperature,
            SensorKind::Humidity => MetricKind::Humidity,
            SensorKind::Airflow => MetricKind::Airflow,
            default => null,
        };
    }

    /**
     * @param  list<string>  $seen
     */
    private function retireDeparted(string $organizationId, string $source, array $seen): void
    {
        $departed = ResourceNode::query()
            ->withoutGlobalScope('organization')
            ->where('organization_id', $organizationId)
            ->where('source', $source)
            ->whereNull('retired_at')
            ->whereNotIn('node_key', $seen)
            ->get();

        foreach ($departed as $node) {
            $this->graph->retire($node);
        }
    }

    /**
     * @return list<string>
     */
    private function providerOrganizationIds(): array
    {
        return array_values(Organization::query()
            ->withoutGlobalScope('organization')
            ->whereIn('type', [
                OrganizationType::Provider->value,
                OrganizationType::Reseller->value,
            ])
            ->pluck('id')
            ->all());
    }
}
