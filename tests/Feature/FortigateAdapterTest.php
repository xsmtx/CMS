<?php

declare(strict_types=1);

use App\Domain\Health\HealthState;
use App\Domain\Infrastructure\Capability;
use App\Domain\Infrastructure\Exceptions\DeviceUnreachable;
use App\Domain\Infrastructure\Network\BgpState;
use App\Domain\Infrastructure\Network\FirewallAction;
use App\Domain\Infrastructure\Network\PortState;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use InfraCMS\NetworkFortigate\FortigateProvider;

/**
 * The first adapter whose *write* side could take a network down, shipped
 * reading only (`phase-c-plan.md` §6).
 *
 * It has never talked to a real FortiGate and these tests do not pretend
 * otherwise: they prove the request shapes, the parsing and the refusals
 * against faked HTTP, which is the code and not the integration.
 *
 * What they are really for is the handful of FortiOS particulars that a
 * careful reading of the API turns up and that nothing else would catch — an
 * `enable`/`disable` string where JSON has a boolean, a deny that is a reject
 * depending on a second flag, an address field that is a list of object names
 * rather than addresses, and a configuration backup that is the one endpoint
 * answering text instead of JSON.
 */
beforeEach(function (): void {
    loadModuleClasses('network-fortigate');

    $this->provider = fn (?string $token = 'a-token'): FortigateProvider => new FortigateProvider(
        baseUrl: 'https://fw1.test',
        token: static fn (): ?string => $token,
        vdom: 'root',
        verifyTls: true,
        timeout: 5,
    );
});

/** @param array<array-key, mixed> $results */
function fortiBody(array $results): array
{
    return ['results' => $results, 'vdom' => 'root', 'status' => 'success'];
}

it('declares the reads and none of the writes the device would accept', function (): void {
    $capabilities = ($this->provider)()->capabilities();

    expect($capabilities->has(Capability::FirewallPolicyRead))->toBeTrue()
        ->and($capabilities->has(Capability::RouteRead))->toBeTrue()
        // The one that matters. FortiOS takes a policy write over this same
        // API; it arrives behind the guarded workflow, not before it.
        ->and($capabilities->has(Capability::FirewallPolicyWrite))->toBeFalse()
        ->and($capabilities->has(Capability::DeviceConfigWrite))->toBeFalse();
});

it('says what the device is without saying where it is', function (): void {
    Http::fake([
        'fw1.test/api/v2/monitor/system/status*' => Http::response([
            'version' => 'v7.4.1',
            'serial' => 'FG100FTK20001234',
        ]),
    ]);

    $health = ($this->provider)()->health();

    expect($health->state)->toBe(HealthState::Ok)
        ->and($health->remoteVersion)->toBe('v7.4.1')
        // Phase 9's rule, and it applies to every adapter: this sentence is
        // rendered on a screen and written to a log.
        ->and($health->message)->not->toContain('fw1.test')
        ->and($health->message)->not->toContain('a-token');
});

it('reports a refused credential as its own answer', function (): void {
    Http::fake(['fw1.test/*' => Http::response([], 401)]);

    $health = ($this->provider)()->health();

    expect($health->state)->toBe(HealthState::Failing)
        ->and($health->message)->toContain('credential');
});

it('is failing rather than throwing when the device is off', function (): void {
    Http::fake(fn (): never => throw new ConnectionException('no route to host'));

    expect(($this->provider)()->health()->state)->toBe(HealthState::Failing);
});

it('reads a policy in the order the device evaluates it', function (): void {
    Http::fake([
        'fw1.test/api/v2/cmdb/firewall/policy*' => Http::response(fortiBody([
            [
                'policyid' => 12,
                'name' => 'web-in',
                'action' => 'accept',
                'status' => 'enable',
                'srcintf' => [['name' => 'wan1']],
                'dstintf' => [['name' => 'dmz']],
                'srcaddr' => [['name' => 'all']],
                'dstaddr' => [['name' => 'WEB_SERVERS'], ['name' => 'WEB_SPARE']],
                'service' => [['name' => 'HTTPS']],
                'logtraffic' => 'all',
            ],
            [
                'policyid' => 13,
                'name' => 'deny-rest',
                'action' => 'deny',
                'status' => 'disable',
                'logtraffic' => 'disable',
            ],
        ])),
    ]);

    $rules = ($this->provider)()->policies('fw1.dc2');

    expect($rules)->toHaveCount(2);

    // First match wins, so the position is the most important thing about a
    // rule — and it is the device's order, not the array's.
    expect($rules[0]->position)->toBe(1)
        ->and($rules[1]->position)->toBe(2);

    expect($rules[0]->id)->toBe('12')
        ->and($rules[0]->action)->toBe(FirewallAction::Allow)
        ->and($rules[0]->enabled)->toBeTrue()
        ->and($rules[0]->logged)->toBeTrue()
        ->and($rules[0]->sourceInterface)->toBe('wan1')
        // The object names as the device wrote them, never resolved: a
        // FortiGate policy refers to objects defined elsewhere, and a
        // platform that resolved them would show a policy that does not
        // match the one on the box.
        ->and($rules[0]->destinations)->toBe(['WEB_SERVERS', 'WEB_SPARE']);

    // `enable`/`disable` where JSON has a boolean, and a rule somebody
    // switched off is a decision that must stay visible.
    expect($rules[1]->enabled)->toBeFalse()
        ->and($rules[1]->action)->toBe(FirewallAction::Deny);
});

/**
 * FortiOS writes `deny` for both a silent drop and an active refusal, and
 * distinguishes them with a second flag. They are different on the wire and
 * different to diagnose — one hangs and one is refused at once — so the flag
 * decides, not the word.
 */
it('tells a drop from a reject', function (): void {
    Http::fake([
        'fw1.test/api/v2/cmdb/firewall/policy*' => Http::response(fortiBody([
            ['policyid' => 1, 'action' => 'deny', 'send-deny-packet' => 'enable'],
        ])),
    ]);

    expect(($this->provider)()->policies('fw1.dc2')[0]->action)->toBe(FirewallAction::Reject);
});

it('reads a port that is up with nothing plugged into it as down', function (): void {
    Http::fake([
        'fw1.test/api/v2/monitor/system/interface*' => Http::response(fortiBody([
            'port1' => [
                'name' => 'port1',
                'status' => 'up',
                'link' => true,
                'speed' => 1000,
                'mac' => 'AA-BB-CC-DD-EE-FF',
                'alias' => 'uplink',
            ],
            // Administratively up, no cable. A down port to everybody except
            // the configuration.
            'port2' => ['name' => 'port2', 'status' => 'up', 'link' => false],
            'port3' => ['name' => 'port3', 'status' => 'disable'],
        ])),
    ]);

    $ports = ($this->provider)()->ports('fw1.dc2');

    expect($ports)->toHaveCount(3)
        ->and($ports[0]->state)->toBe(PortState::Up)
        ->and($ports[0]->speedMbps)->toBe(1000)
        ->and($ports[0]->description)->toBe('uplink')
        // One spelling, whatever the firmware writes: a graph keyed on the
        // difference holds the same NIC twice.
        ->and($ports[0]->macAddress)->toBe('aa:bb:cc:dd:ee:ff')
        ->and($ports[1]->state)->toBe(PortState::Down)
        ->and($ports[2]->state)->toBe(PortState::Disabled);
});

/**
 * FortiOS writes `ip` and `mask` as separate dotted quads, so the notation is
 * built rather than read — and `0.0.0.0` is what an unconfigured interface
 * answers, not an address.
 */
it('builds the cidr notation from the address and the netmask', function (): void {
    Http::fake([
        'fw1.test/api/v2/monitor/system/interface*' => Http::response(fortiBody([
            ['name' => 'port1', 'status' => 'up', 'ip' => '198.51.100.1', 'mask' => '255.255.255.0'],
            ['name' => 'port2', 'status' => 'up', 'ip' => '10.0.0.1', 'mask' => '255.255.255.252'],
            // Nothing configured on it.
            ['name' => 'port3', 'status' => 'up', 'ip' => '0.0.0.0', 'mask' => '0.0.0.0'],
            // A mask that is not contiguous is a device answering nonsense,
            // and nonsense comes back as no length rather than as a plausible
            // number.
            ['name' => 'port4', 'status' => 'up', 'ip' => '10.1.0.1', 'mask' => '255.0.255.0'],
        ])),
    ]);

    $ports = ($this->provider)()->ports('fw1.dc2');

    expect($ports[0]->addresses)->toBe(['198.51.100.1/24'])
        ->and($ports[1]->addresses)->toBe(['10.0.0.1/30'])
        ->and($ports[2]->addresses)->toBe([])
        ->and($ports[3]->addresses)->toBe(['10.1.0.1']);
});

/**
 * A FortiGate has no VLAN table: a VLAN there *is* a subinterface. So two
 * interfaces on one tag are one VLAN with two ports, and an adapter that
 * assigned rather than accumulated would report the last one and lose the
 * first.
 */
it('gathers the subinterfaces on one tag into one vlan', function (): void {
    Http::fake([
        'fw1.test/api/v2/monitor/system/interface*' => Http::response(fortiBody([
            ['name' => 'port1.100', 'status' => 'up', 'vlanid' => 100, 'alias' => 'customers'],
            ['name' => 'port2.100', 'status' => 'up', 'vlanid' => 100],
            ['name' => 'port3.200', 'status' => 'up', 'vlanid' => 200],
            // No tag: an untagged physical port is not a VLAN.
            ['name' => 'port4', 'status' => 'up'],
        ])),
    ]);

    $vlans = ($this->provider)()->vlans('fw1.dc2');

    expect($vlans)->toHaveCount(2)
        ->and($vlans[0]->tag)->toBe(100)
        ->and($vlans[0]->name)->toBe('customers')
        ->and($vlans[0]->ports)->toBe(['port1.100', 'port2.100'])
        ->and($vlans[1]->tag)->toBe(200);
});

it('keeps the three bgp failures apart', function (): void {
    Http::fake([
        'fw1.test/api/v2/monitor/router/bgp/neighbors*' => Http::response(fortiBody([
            ['neighbor_ip' => '198.51.100.1', 'state' => 'Established', 'remote_as' => 64500,
                'accepted_prefixes' => 912_000],
            // TCP will not come up: a firewall or a cable.
            ['neighbor_ip' => '198.51.100.5', 'state' => 'Active', 'remote_as' => 64501],
            // Administratively shut, which is a decision rather than a fault.
            ['neighbor_ip' => '198.51.100.9', 'state' => 'Idle'],
        ])),
    ]);

    $sessions = ($this->provider)()->bgpSessions('fw1.dc2');

    expect($sessions[0]->state)->toBe(BgpState::Established)
        ->and($sessions[0]->state->isUp())->toBeTrue()
        // A session can be established and carrying nothing, which looks
        // healthy on every status page and means the transit is down.
        ->and($sessions[0]->prefixesReceived)->toBe(912_000)
        ->and($sessions[1]->state)->toBe(BgpState::Active)
        ->and($sessions[1]->state->isUp())->toBeFalse()
        ->and($sessions[2]->state)->toBe(BgpState::Idle);
});

it('reads a configuration as text and fingerprints it', function (): void {
    Http::fake([
        'fw1.test/api/v2/monitor/system/config/backup*' => Http::response(
            "config system global\r\n    set hostname \"fw1\"\r\nend\r\n",
        ),
    ]);

    $configuration = ($this->provider)()->configuration('fw1.dc2');

    expect($configuration->text)->toContain('set hostname')
        ->and($configuration->format)->toBe('fortios');

    // Line endings normalised first: the same configuration read over two
    // transports must not fingerprint differently, or every apply on that box
    // would be refused as "somebody else has edited it".
    $unix = str_replace("\r\n", "\n", $configuration->text);

    expect($configuration->fingerprint())->toBe(hash('sha256', $unix));
});

/**
 * Reading it as JSON would answer null and store an empty configuration,
 * which the diff step would read as "everything has been deleted".
 */
it('refuses an empty configuration rather than storing one', function (): void {
    Http::fake(['fw1.test/api/v2/monitor/system/config/backup*' => Http::response('   ')]);

    expect(fn () => ($this->provider)()->configuration('fw1.dc2'))
        ->toThrow(DeviceUnreachable::class);
});

/**
 * Named refusals, for the reason `PackageRefused` gives: a box that is off, a
 * credential that was rotated and a firmware that changed its API are three
 * different phone calls, and "it did not work" makes them one.
 */
it('names the device it could not reach, and nothing else', function (): void {
    Http::fake(['fw1.test/*' => Http::response([], 403)]);

    expect(fn () => ($this->provider)()->policies('fw1.dc2'))
        ->toThrow(DeviceUnreachable::class, 'fw1.dc2');

    try {
        ($this->provider)()->policies('fw1.dc2');
    } catch (DeviceUnreachable $refusal) {
        expect($refusal->getMessage())->not->toContain('fw1.test')
            ->and($refusal->getMessage())->not->toContain('a-token');
    }
});

it('asks about one vdom and caps what it takes', function (): void {
    Http::fake(['fw1.test/*' => Http::response(fortiBody([]))]);

    ($this->provider)()->policies('fw1.dc2');

    Http::assertSent(function ($request): bool {
        $url = $request->url();

        return str_contains($url, 'vdom=root') && str_contains($url, 'count=500');
    });
});
