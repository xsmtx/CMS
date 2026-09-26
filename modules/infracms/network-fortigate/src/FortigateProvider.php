<?php

declare(strict_types=1);

namespace InfraCMS\NetworkFortigate;

use App\Domain\Health\HealthState;
use App\Domain\Infrastructure\AdapterHealth;
use App\Domain\Infrastructure\Capability;
use App\Domain\Infrastructure\CapabilitySet;
use App\Domain\Infrastructure\Contracts\FirewallProvider;
use App\Domain\Infrastructure\Contracts\NetworkDeviceProvider;
use App\Domain\Infrastructure\Contracts\NetworkDeviceWriter;
use App\Domain\Infrastructure\Contracts\RoutingProvider;
use App\Domain\Infrastructure\Contracts\SwitchProvider;
use App\Domain\Infrastructure\Exceptions\DeviceUnreachable;
use App\Domain\Infrastructure\Network\BgpSession;
use App\Domain\Infrastructure\Network\BgpState;
use App\Domain\Infrastructure\Network\DeviceConfiguration;
use App\Domain\Infrastructure\Network\DeviceDescription;
use App\Domain\Infrastructure\Network\DevicePort;
use App\Domain\Infrastructure\Network\FirewallAction;
use App\Domain\Infrastructure\Network\FirewallRule;
use App\Domain\Infrastructure\Network\PortState;
use App\Domain\Infrastructure\Network\RouteEntry;
use App\Domain\Infrastructure\Network\SessionSummary;
use App\Domain\Infrastructure\Network\VlanDescriptor;
use App\Domain\Infrastructure\RateLimits;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * FortiOS, over its REST API.
 *
 * One object implementing four contracts, because one chassis is a firewall, a
 * switch and a router — the case `AdapterArea` was split for. An installation
 * whose FortiGate is only a firewall still gets the switch and routing reads;
 * they answer with empty lists, which is the truth rather than an error.
 *
 * **Reads only, and that is the design rather than an unfinished half.**
 * FortiOS will happily take a policy write over this same API, and a firewall
 * policy write is the most consequential thing this platform could ever do. It
 * arrives behind §6's guarded workflow — request, validate, diff against what
 * the box says *now*, authorize, back up, apply, verify — and putting a write
 * method here first would be building the shortcut around that workflow before
 * building the workflow.
 *
 * **The target is a node key and it is never put in the URL.** This adapter
 * talks to one device, configured by address; the target names which device
 * core *thinks* it is asking about and travels into the refusals so an
 * operator can read which box did not answer. A node key is a hostname
 * somebody typed, and a hostname interpolated into a URL is a request to
 * whatever that string resolves to.
 *
 * **Every list is capped.** A firewall policy can be ten thousand rules and a
 * routing table can be a million; a platform that asked for all of them would
 * be a platform that ran out of memory on the first call.
 *
 * It has never talked to a real FortiGate. Every request shape and every parse
 * here is tested against faked HTTP, which proves the code and not the
 * integration.
 */
final readonly class FortigateProvider implements FirewallProvider, NetworkDeviceProvider, NetworkDeviceWriter, RoutingProvider, SwitchProvider
{
    /**
     * How many rows to take from a list endpoint.
     *
     * Larger than any policy an operator maintains by hand, smaller than a
     * table nobody could read. A device with more is a device this adapter
     * reports the first page of.
     */
    private const int PAGE = 500;

    /**
     * @param  Closure(): ?string  $token
     */
    public function __construct(
        private string $baseUrl,
        private Closure $token,
        private string $vdom = 'root',
        private bool $verifyTls = true,
        private int $timeout = 15,
    ) {}

    public function key(): string
    {
        return 'fortigate';
    }

    public function name(): string
    {
        return 'FortiGate';
    }

    public function vendor(): string
    {
        return 'Fortinet';
    }

    public function capabilities(): CapabilitySet
    {
        /*
         * The reads, and none of the writes this device would accept. A
         * capability an adapter declares is a button core is allowed to
         * offer, and there is nothing behind those buttons yet.
         */
        return CapabilitySet::of([
            Capability::DeviceInventoryRead,
            Capability::DeviceConfigRead,
            /*
             * The one write, and declaring it grants nothing.
             * `resource_adapters.writes_enabled` is false until an operator
             * turns it on deliberately and audibly, and the registry makes an
             * unenabled capability *absent* rather than refused — so a screen
             * cannot offer a button this platform would then decline. The only
             * caller is `ApplyNetworkChange`, from a change somebody other
             * than its requester approved and which has been backed up.
             */
            Capability::DeviceConfigWrite,
            Capability::FirewallPolicyRead,
            Capability::FirewallSessionRead,
            Capability::SwitchPortRead,
            Capability::SwitchVlanRead,
            Capability::RouteRead,
            Capability::BgpSessionRead,
        ]);
    }

    public function limits(): RateLimits
    {
        /*
         * A device, not a monitoring system: it answers about itself, so
         * there is no batch. The concurrency is the number that matters —
         * FortiOS's management plane shares a CPU with the thing actually
         * passing traffic, and four simultaneous inventory reads on a busy
         * box is a way to make a firewall drop packets.
         */
        return new RateLimits(perMinute: 60, concurrency: 2, batchSize: 0);
    }

    public function health(): AdapterHealth
    {
        try {
            $response = $this->request()->get('/api/v2/monitor/system/status');
        } catch (Throwable) {
            return AdapterHealth::failing('The FortiGate could not be reached.');
        }

        if ($response->status() === 401 || $response->status() === 403) {
            return AdapterHealth::failing('The FortiGate refused the credential.');
        }

        if (! $response->successful()) {
            return AdapterHealth::failing('The FortiGate answered '.$response->status().'.');
        }

        $version = $response->json('version');

        return new AdapterHealth(
            HealthState::Ok,
            'The FortiGate answered.',
            remoteVersion: is_string($version) ? $version : null,
            checkedAt: CarbonImmutable::now(),
        );
    }

    public function describe(string $target): DeviceDescription
    {
        $status = $this->read($target, '/api/v2/monitor/system/status');

        return new DeviceDescription(
            target: $target,
            model: $this->text($status, 'model_name'),
            serial: $this->text($status, 'serial'),
            firmware: $this->text($status, 'version'),
            hostname: $this->text($status, 'hostname'),
            uptimeSeconds: $this->number($status, 'uptime'),
            ports: $this->ports($target),
        );
    }

    public function configuration(string $target): DeviceConfiguration
    {
        try {
            $response = $this->request()->get('/api/v2/monitor/system/config/backup', [
                'scope' => 'global',
            ]);
        } catch (Throwable) {
            throw DeviceUnreachable::noAnswer($target);
        }

        $this->refuseUnlessAnswered($target, $response->status());

        /*
         * A configuration backup comes back as text, not as JSON — the one
         * endpoint on this API that does. Reading it as JSON would answer
         * null and store an empty configuration, which the diff step would
         * then read as "everything has been deleted".
         */
        $text = $response->body();

        if (trim($text) === '') {
            throw DeviceUnreachable::unreadable($target, 'an empty configuration');
        }

        return new DeviceConfiguration(
            target: $target,
            text: $text,
            retrievedAt: CarbonImmutable::now(),
            format: 'fortios',
        );
    }

    /**
     * @return list<FirewallRule>
     */
    public function policies(string $target): array
    {
        $rules = [];
        $position = 0;

        foreach ($this->rows($target, '/api/v2/cmdb/firewall/policy') as $row) {
            $position++;

            $id = $this->text($row, 'policyid') ?? $this->text($row, 'name');

            if ($id === null) {
                continue;
            }

            $rules[] = new FirewallRule(
                id: $id,
                position: $position,
                action: $this->action($row),
                name: $this->text($row, 'name'),
                // FortiOS says `enable`/`disable` where JSON has a boolean.
                enabled: ($row['status'] ?? 'enable') !== 'disable',
                sources: $this->names($row['srcaddr'] ?? null),
                destinations: $this->names($row['dstaddr'] ?? null),
                services: $this->names($row['service'] ?? null),
                sourceInterface: $this->firstName($row['srcintf'] ?? null),
                destinationInterface: $this->firstName($row['dstintf'] ?? null),
                logged: ($row['logtraffic'] ?? 'disable') !== 'disable',
            );
        }

        return $rules;
    }

    public function sessions(string $target): SessionSummary
    {
        $summary = $this->read($target, '/api/v2/monitor/firewall/session/select', [
            'summary' => 'true',
            'count' => 1,
        ]);

        $details = is_array($summary['details'] ?? null) ? $summary['details'] : [];

        $byProtocol = [];

        foreach (['tcp', 'udp', 'icmp'] as $protocol) {
            $count = $this->number($details, $protocol);

            if ($count !== null) {
                $byProtocol[$protocol] = $count;
            }
        }

        return new SessionSummary(
            target: $target,
            count: $this->number($summary, 'session_count') ?? array_sum($byProtocol),
            capacity: $this->number($summary, 'session_limit'),
            byProtocol: $byProtocol,
        );
    }

    /**
     * @return list<DevicePort>
     */
    public function ports(string $target): array
    {
        $ports = [];

        foreach ($this->rows($target, '/api/v2/monitor/system/interface') as $name => $row) {
            $port = $this->text($row, 'name') ?? (is_string($name) ? $name : null);

            if ($port === null) {
                continue;
            }

            $ports[] = new DevicePort(
                name: $port,
                state: $this->portState($row),
                description: $this->text($row, 'alias'),
                speedMbps: $this->number($row, 'speed'),
                macAddress: $this->mac($this->text($row, 'mac')),
                untaggedVlan: $this->number($row, 'vlanid'),
                addresses: $this->addresses($row),
            );
        }

        return $ports;
    }

    /**
     * @return list<VlanDescriptor>
     */
    public function vlans(string $target): array
    {
        /*
         * Built from the interfaces rather than from a VLAN endpoint, because
         * a FortiGate has no VLAN table of its own: a VLAN there *is* a
         * subinterface. Two interfaces on one tag is one VLAN with two ports,
         * which is why this accumulates rather than assigns.
         *
         * @var array<int, array{name: ?string, ports: list<string>}>
         */
        $vlans = [];

        foreach ($this->ports($target) as $port) {
            $tag = $port->untaggedVlan;

            if ($tag === null || $tag <= 0) {
                continue;
            }

            $vlans[$tag] ??= ['name' => null, 'ports' => []];
            $vlans[$tag]['name'] ??= $port->description;
            $vlans[$tag]['ports'][] = $port->name;
        }

        ksort($vlans);

        $descriptors = [];

        foreach ($vlans as $tag => $vlan) {
            $descriptors[] = new VlanDescriptor(
                tag: $tag,
                name: $vlan['name'],
                ports: $vlan['ports'],
            );
        }

        return $descriptors;
    }

    /**
     * @return list<RouteEntry>
     */
    public function routes(string $target, ?string $prefix = null): array
    {
        $query = $prefix === null ? [] : ['ip_mask' => $prefix];

        $routes = [];

        foreach ($this->rows($target, '/api/v2/monitor/router/ipv4', $query) as $row) {
            $destination = $this->text($row, 'ip_mask');

            if ($destination === null) {
                continue;
            }

            $routes[] = new RouteEntry(
                prefix: $destination,
                nextHop: $this->text($row, 'gateway'),
                protocol: $this->text($row, 'type'),
                interface: $this->text($row, 'interface'),
                distance: $this->number($row, 'distance'),
                metric: $this->number($row, 'metric'),
            );
        }

        return $routes;
    }

    /**
     * @return list<BgpSession>
     */
    public function bgpSessions(string $target): array
    {
        $sessions = [];

        foreach ($this->rows($target, '/api/v2/monitor/router/bgp/neighbors') as $row) {
            $peer = $this->text($row, 'neighbor_ip');

            if ($peer === null) {
                continue;
            }

            $sessions[] = new BgpSession(
                peer: $peer,
                state: $this->bgpState($this->text($row, 'state')),
                remoteAsn: $this->number($row, 'remote_as'),
                localAsn: $this->number($row, 'local_as'),
                prefixesReceived: $this->number($row, 'accepted_prefixes'),
                prefixesAdvertised: $this->number($row, 'advertised_prefixes'),
                uptimeSeconds: $this->number($row, 'uptime'),
            );
        }

        return $sessions;
    }

    public function applyConfiguration(string $target, string $configuration): void
    {
        $this->restore($target, $configuration);
    }

    public function restoreConfiguration(string $target, DeviceConfiguration $backup): void
    {
        $this->restore($target, $backup->text);
    }

    /**
     * One GET that must answer, decoded.
     *
     * @param  array<string, string|int>  $query
     * @return array<array-key, mixed>
     */
    private function read(string $target, string $path, array $query = []): array
    {
        try {
            $response = $this->request()->get($path, $query);
        } catch (Throwable) {
            throw DeviceUnreachable::noAnswer($target);
        }

        $this->refuseUnlessAnswered($target, $response->status());

        $body = $response->json();

        if (! is_array($body)) {
            throw DeviceUnreachable::unreadable($target, 'something that was not JSON');
        }

        $results = $body['results'] ?? $body;

        return is_array($results) ? $results : [];
    }

    /**
     * A list endpoint's rows.
     *
     * FortiOS answers `results` as a list on the cmdb endpoints and as an
     * object keyed by interface name on some monitor ones, so the key is
     * preserved and the one caller that needs it reads it. Forcing both into
     * a list would lose the interface names on the endpoint that only has
     * them there.
     *
     * @param  array<string, string|int>  $query
     * @return array<array-key, array<array-key, mixed>>
     */
    private function rows(string $target, string $path, array $query = []): array
    {
        $results = $this->read(
            $target,
            $path,
            $query + ['vdom' => $this->vdom, 'count' => self::PAGE],
        );

        return array_filter($results, 'is_array');
    }

    private function refuseUnlessAnswered(string $target, int $status): void
    {
        if ($status === 401 || $status === 403) {
            throw DeviceUnreachable::refused($target);
        }

        if ($status >= 400) {
            throw DeviceUnreachable::unreadable($target, 'status '.$status);
        }
    }

    /**
     * @param  array<array-key, mixed>  $row
     */
    private function action(array $row): FirewallAction
    {
        return match ($row['action'] ?? null) {
            'accept' => FirewallAction::Allow,
            // FortiOS writes `deny` for both, and distinguishes them with a
            // separate flag — so the flag decides, not the word.
            'deny' => ($row['send-deny-packet'] ?? 'disable') === 'enable'
                ? FirewallAction::Reject
                : FirewallAction::Deny,
            default => FirewallAction::Unknown,
        };
    }

    /**
     * `status` is the administrative state and `link` is the physical one, and
     * a port can be administratively up with no cable in it — which is a down
     * port to everybody except the configuration.
     *
     * @param  array<array-key, mixed>  $row
     */
    private function portState(array $row): PortState
    {
        return match (true) {
            ($row['status'] ?? null) === 'down' => PortState::Down,
            ($row['status'] ?? null) === 'disable' => PortState::Disabled,
            ($row['status'] ?? null) === 'up' && ($row['link'] ?? true) === false => PortState::Down,
            ($row['status'] ?? null) === 'up' => PortState::Up,
            default => PortState::Unknown,
        };
    }

    /**
     * Both writes are the same endpoint on this vendor, and the contract is
     * still right to keep them apart.
     *
     * FortiOS takes a whole configuration through `config/restore` whether it
     * is a new one or an old one, so here the two collapse into one call. A
     * device that took its configuration in fragments would have a restore
     * that is genuinely a different operation — which is why the interface has
     * two methods rather than one `write()` that every vendor after the first
     * would have to lie about.
     */
    private function restore(string $target, string $configuration): void
    {
        try {
            $response = $this->request()
                ->asMultipart()
                ->attach('file', $configuration, 'config.conf')
                ->post('/api/v2/monitor/system/config/restore', [
                    'source' => 'upload',
                    'scope' => 'global',
                    'vdom' => $this->vdom,
                ]);
        } catch (Throwable) {
            throw DeviceUnreachable::noAnswer($target);
        }

        $this->refuseUnlessAnswered($target, $response->status());
    }

    /**
     * The addresses configured on an interface, in CIDR notation.
     *
     * FortiOS writes `ip` and `mask` as separate dotted quads and keeps IPv6
     * somewhere else entirely, which is why this builds the notation rather
     * than reading it: a platform that stored `255.255.255.0` beside an
     * address would be a platform that could not answer "is this inside that
     * prefix" without doing the arithmetic anyway.
     *
     * `0.0.0.0` is what an unconfigured interface answers, not an address.
     *
     * @param  array<array-key, mixed>  $row
     * @return list<string>
     */
    private function addresses(array $row): array
    {
        $ip = $this->text($row, 'ip');

        if ($ip === null || $ip === '0.0.0.0') {
            return [];
        }

        $mask = $this->text($row, 'mask');
        $length = $mask === null ? null : $this->prefixLength($mask);

        return [$length === null ? $ip : $ip.'/'.$length];
    }

    /**
     * A dotted-quad netmask as a prefix length.
     *
     * Counting the set bits rather than matching a table: a table would have
     * to be right about all thirty-three of them, and a mask that is not
     * contiguous is a device answering nonsense, which comes back as null
     * rather than as a plausible number.
     */
    private function prefixLength(string $mask): ?int
    {
        $packed = inet_pton($mask);

        if ($packed === false) {
            return null;
        }

        $bits = '';

        foreach (str_split($packed) as $byte) {
            $bits .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT);
        }

        return preg_match('/^1*0*$/', $bits) === 1 ? substr_count($bits, '1') : null;
    }

    private function bgpState(?string $state): BgpState
    {
        return match (strtolower((string) $state)) {
            'established' => BgpState::Established,
            'idle' => BgpState::Idle,
            'connect' => BgpState::Connect,
            'active' => BgpState::Active,
            'opensent' => BgpState::OpenSent,
            'openconfirm' => BgpState::OpenConfirm,
            default => BgpState::Unknown,
        };
    }

    /**
     * FortiOS writes a MAC with colons and in lower case already; normalised
     * anyway, because an adapter that trusted a vendor's formatting is an
     * adapter that holds the same NIC twice the day the firmware changes.
     */
    private function mac(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $hex = preg_replace('/[^0-9a-f]/', '', strtolower($value)) ?? '';

        if (strlen($hex) !== 12) {
            return null;
        }

        return implode(':', str_split($hex, 2));
    }

    /**
     * The names inside a FortiOS object reference.
     *
     * Every address, service and interface field on a policy is a list of
     * `{"name": "..."}` rather than a literal address: a FortiGate policy
     * refers to objects defined elsewhere. Resolving them would be this
     * platform reimplementing a vendor's object model and showing an operator
     * a policy that does not match the one on the box.
     *
     * @return list<string>
     */
    private function names(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $names = [];

        foreach ($value as $entry) {
            if (is_array($entry) && is_string($entry['name'] ?? null)) {
                $names[] = $entry['name'];
            }
        }

        return $names;
    }

    private function firstName(mixed $value): ?string
    {
        return $this->names($value)[0] ?? null;
    }

    /**
     * @param  array<array-key, mixed>  $row
     */
    private function text(array $row, string $key): ?string
    {
        $value = $row[$key] ?? null;

        if (is_string($value)) {
            return $value === '' ? null : $value;
        }

        return is_int($value) ? (string) $value : null;
    }

    /**
     * @param  array<array-key, mixed>  $row
     */
    private function number(array $row, string $key): ?int
    {
        $value = $row[$key] ?? null;

        if (is_int($value)) {
            return $value;
        }

        return is_string($value) && is_numeric($value) ? (int) $value : null;
    }

    private function request(): PendingRequest
    {
        $request = Http::baseUrl($this->baseUrl)
            ->timeout($this->timeout)
            ->acceptJson()
            ->withOptions(['verify' => $this->verifyTls]);

        $token = ($this->token)();

        return is_string($token) && $token !== ''
            ? $request->withToken($token)
            : $request;
    }
}
