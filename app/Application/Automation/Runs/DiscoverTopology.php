<?php

declare(strict_types=1);

namespace App\Application\Automation\Runs;

use App\Application\Infrastructure\AdapterRegistry;
use App\Application\Infrastructure\RegisteredAdapter;
use App\Application\Infrastructure\ResourceGraph;
use App\Domain\Automation\Contracts\AutomationRun;
use App\Domain\Automation\ItemOutcome;
use App\Domain\Automation\RunItem;
use App\Domain\Automation\RunSummary;
use App\Domain\Infrastructure\Capability;
use App\Domain\Infrastructure\Contracts\NetworkDeviceProvider;
use App\Domain\Infrastructure\Contracts\SwitchProvider;
use App\Domain\Infrastructure\Network\DeviceDescription;
use App\Domain\Infrastructure\Network\DevicePort;
use App\Domain\Infrastructure\Relation;
use App\Domain\Organizations\OrganizationType;
use App\Infrastructure\Organizations\Models\Organization;
use App\Infrastructure\Resources\Models\ResourceAdapter;
use App\Infrastructure\Resources\Models\ResourceNode;
use App\Support\Logging\SecretRedactor;
use App\Support\Organizations\OrganizationContext;
use Throwable;

/**
 * Asks every network device what it is, and writes the answer into the graph.
 *
 * Phase C §6's third item. `ProjectCoreResources` puts the rows this platform
 * owns into the graph; this puts the things it has only ever been *told* about
 * — a device, its ports, its VLANs — so the containment walk the graph already
 * does reaches from a switch port down to the customer whose service is behind
 * it.
 *
 * Four rules, and each one is the answer to a way this could go wrong.
 *
 * **An adapter is one device.** A network-device module is configured with one
 * address and speaks to one box, so there is nothing to enumerate: the adapter
 * *is* the target. That is why `describe()` is handed the adapter's own key
 * rather than a list of node keys the way `CollectTelemetry` does — there is no
 * inventory of devices to draw the list from, and inventing one would be this
 * platform guessing at hardware it has never seen.
 *
 * **The serial is the identity.** A hostname is changed by whoever last
 * configured the box; a serial survives a rename, a firmware upgrade and being
 * racked somewhere else. A device that reports no serial falls back to the
 * adapter key, which is stable for a different reason — it is what an operator
 * typed into the module's settings.
 *
 * **It retires only what it wrote.** Every node carries `source`, and the sweep
 * closes nodes with this run's source that the device no longer reports — a
 * port that was removed from a chassis. Retiring by kind instead would mean a
 * second adapter's ports vanishing every time this one ran, and
 * `ProjectCoreResources` already says why core must not believe it owns rows it
 * has never seen.
 *
 * **One device failing never stops the others**, and a failure is recorded
 * against the adapter row rather than thrown — through the redactor, because a
 * device's exception message is a likely place for a credential to reach a
 * database column that is then read on a screen.
 *
 * Nothing here writes to a device. This is the read half of §6 and the write
 * half is the guarded workflow, which arrives with the change records.
 */
final readonly class DiscoverTopology implements AutomationRun
{
    /** A device, a port and a VLAN as the graph names them. */
    private const string DeviceKind = 'network_device';

    private const string PortKind = 'device_port';

    private const string VlanKind = 'vlan';

    /**
     * An address the device reports on one of its own interfaces.
     *
     * A node, not an `ip_addresses` row. The two answer different questions
     * and must not be merged: that table is the seller's plan — what somebody
     * intends to hand out — and this is what a box says it is actually
     * wearing. The whole value of discovery is being able to say they
     * disagree, which is impossible once one has overwritten the other.
     */
    private const string AddressKind = 'ip_address';

    public function __construct(
        private OrganizationContext $organizations,
        private AdapterRegistry $registry,
        private ResourceGraph $graph,
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

        if (! $adapter instanceof NetworkDeviceProvider) {
            return $summary;
        }

        if (! $registered->permitted()->has(Capability::DeviceInventoryRead)) {
            // Examined and skipped rather than silently passed over: an
            // adapter an operator turned off is why nothing arrived, and the
            // run record is where that question gets asked.
            return $summary->examining()->skipping();
        }

        $summary = $summary->examining();

        try {
            $description = $adapter->describe($registered->descriptor->key);
            $written = $this->write($organizationId, $registered, $description);
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
                '%d ports, %d addresses, %d VLANs',
                $written['ports'],
                $written['addresses'],
                $written['vlans'],
            ),
        ));
    }

    /**
     * @return array{ports: int, addresses: int, vlans: int}
     */
    private function write(
        string $organizationId,
        RegisteredAdapter $registered,
        DeviceDescription $description,
    ): array {
        // `resource_nodes.source` is 48 characters. An adapter key longer than
        // the remainder would fail the insert rather than the discovery, which
        // is a database error where a truncated label belongs.
        $source = substr('topology:'.$registered->descriptor->key, 0, 48);

        $device = $this->graph->upsertNode(
            organizationId: $organizationId,
            kind: self::DeviceKind,
            nodeKey: $this->deviceKey($registered, $description),
            label: $this->deviceLabel($registered, $description),
            source: $source,
            attributes: array_filter([
                'model' => $description->model,
                'serial' => $description->serial,
                'firmware' => $description->firmware,
                'hostname' => $description->hostname,
                'vendor' => $registered->descriptor->vendor,
            ], static fn (?string $value): bool => $value !== null),
        );

        $seen = [$device->node_key => true];

        $addresses = 0;

        foreach ($description->ports as $port) {
            $node = $this->writePort($organizationId, $device, $port, $source);
            $seen[$node->node_key] = true;

            foreach ($port->addresses as $address) {
                $addressNode = $this->writeAddress($organizationId, $node, $address, $source);
                $seen[$addressNode->node_key] = true;
                $addresses++;
            }
        }

        $vlans = $this->writeVlans($organizationId, $registered, $device, $source, $seen);

        $this->retireDeparted($organizationId, $source, array_keys($seen));

        return [
            'ports' => count($description->ports),
            'addresses' => $addresses,
            'vlans' => $vlans,
        ];
    }

    private function writePort(
        string $organizationId,
        ResourceNode $device,
        DevicePort $port,
        string $source,
    ): ResourceNode {
        $node = $this->graph->upsertNode(
            organizationId: $organizationId,
            kind: self::PortKind,
            // Qualified by the device, because `port1` is what half the
            // network is called and a node key is unique per organization.
            nodeKey: $device->node_key.'/'.$port->name,
            label: $port->name,
            source: $source,
            attributes: array_filter([
                'state' => $port->state->value,
                'description' => $port->description,
                'speed_mbps' => $port->speedMbps,
                'mac' => $port->macAddress,
                // The VLAN as an attribute rather than an edge. A VLAN does
                // not physically contain a port, and `Relation` is a closed
                // list on purpose — inventing a semantic to carry a number is
                // how a traversal starts answering questions nobody asked.
                'vlan' => $port->untaggedVlan,
            ], static fn (mixed $value): bool => $value !== null),
        );

        $this->graph->attach($device, $node, Relation::Contains, source: $source);

        return $node;
    }

    /**
     * An address the device reports on one of its own interfaces.
     *
     * Contained by the **port**, not by the device, which is what makes the
     * spine §2 draws reach all the way down: Router > Port > Address. It is
     * also the honest direction — an address is configured on an interface,
     * and a device with two interfaces on the same subnet is a device where
     * knowing which one matters.
     */
    private function writeAddress(
        string $organizationId,
        ResourceNode $port,
        string $address,
        string $source,
    ): ResourceNode {
        $node = $this->graph->upsertNode(
            organizationId: $organizationId,
            kind: self::AddressKind,
            // Qualified by the port for the same reason a port is qualified by
            // its device: two boxes on one management subnet is ordinary, and
            // a node key is unique per organization.
            nodeKey: $port->node_key.'/'.$address,
            label: $address,
            source: $source,
        );

        $this->graph->attach($port, $node, Relation::Contains, source: $source);

        return $node;
    }

    /**
     * VLANs, when the same box also answers as a switch.
     *
     * One chassis is a firewall, a switch and a router, so this asks the same
     * adapter again through a different contract rather than looking for a
     * second one.
     *
     * @param  array<string, true>  $seen
     */
    private function writeVlans(
        string $organizationId,
        RegisteredAdapter $registered,
        ResourceNode $device,
        string $source,
        array &$seen,
    ): int {
        $adapter = $registered->adapter();

        if (! $adapter instanceof SwitchProvider) {
            return 0;
        }

        if (! $registered->permitted()->has(Capability::SwitchVlanRead)) {
            return 0;
        }

        $written = 0;

        foreach ($adapter->vlans($registered->descriptor->key) as $vlan) {
            $node = $this->graph->upsertNode(
                organizationId: $organizationId,
                kind: self::VlanKind,
                nodeKey: $device->node_key.'/vlan/'.$vlan->tag,
                label: $vlan->name ?? ('VLAN '.$vlan->tag),
                source: $source,
                attributes: array_filter([
                    'tag' => $vlan->tag,
                    'ports' => $vlan->ports === [] ? null : $vlan->ports,
                ], static fn (mixed $value): bool => $value !== null),
            );

            $this->graph->attach($device, $node, Relation::Contains, source: $source);

            $seen[$node->node_key] = true;
            $written++;
        }

        return $written;
    }

    /**
     * A device that reports no serial is keyed by the adapter instead.
     *
     * Not by the hostname, although the device reports one: a hostname is
     * changed by whoever last configured the box, and a node key that moved
     * would leave the old node behind as a device that had apparently
     * vanished. The adapter key is what an operator typed into the module's
     * settings, which is as stable as anything this platform has.
     */
    private function deviceKey(RegisteredAdapter $registered, DeviceDescription $description): string
    {
        $serial = $description->serial;

        return $serial === null || trim($serial) === ''
            ? $registered->descriptor->key
            : $serial;
    }

    private function deviceLabel(RegisteredAdapter $registered, DeviceDescription $description): string
    {
        foreach ([$description->hostname, $description->model] as $candidate) {
            if (is_string($candidate) && trim($candidate) !== '') {
                return $candidate;
            }
        }

        return $registered->descriptor->name;
    }

    /**
     * Close what this adapter no longer reports.
     *
     * Scoped to this run's own `source`, which is the whole of why `source`
     * exists on a node. Retiring by kind would take a second adapter's ports
     * with it every time this one ran, and retiring nothing would leave a port
     * that was pulled out of a chassis in the graph for ever.
     *
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
        $ids = Organization::query()
            ->withoutGlobalScope('organization')
            ->where('type', OrganizationType::Provider->value)
            ->pluck('id')
            ->all();

        return array_values(array_filter($ids, is_string(...)));
    }
}
