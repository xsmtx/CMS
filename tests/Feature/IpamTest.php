<?php

declare(strict_types=1);

use App\Application\Network\AllocateAddress;
use App\Application\Network\AssignAddress;
use App\Application\Network\Exceptions\AddressingFailed;
use App\Application\Network\SavePrefix;
use App\Domain\Network\AddressState;
use App\Domain\Network\IpFamily;
use App\Domain\Network\IpPrefix;
use App\Domain\Organizations\OrganizationType;
use App\Infrastructure\Network\Models\IpAddressRecord;
use App\Infrastructure\Network\Models\IpAssignment;
use App\Infrastructure\Network\Models\IpPool;
use App\Infrastructure\Network\Models\IpPrefixRecord;
use App\Infrastructure\Organizations\Models\Organization;
use App\Infrastructure\Provisioning\Models\Service;
use App\Support\Organizations\OrganizationContext;
use Database\Seeders\ProviderOrganizationSeeder;
use Illuminate\Database\QueryException;

/**
 * Addressing, as rows.
 *
 * The arithmetic is pinned in `tests/Unit/IpAddressTest.php`; this is about what
 * the platform does with it — who may hold an address, what happens when it is
 * given back, and the two rules that keep the prefix tree honest.
 */
beforeEach(function (): void {
    $this->seed(ProviderOrganizationSeeder::class);

    $this->provider = Organization::query()
        ->where('type', OrganizationType::Provider->value)
        ->firstOrFail();

    app(OrganizationContext::class)->set($this->provider->id);

    $this->pool = IpPool::factory()->create([
        'organization_id' => $this->provider->id,
        'name' => 'Customer v4',
        'family' => IpFamily::V4->value,
    ]);

    $this->prefix = fn (string $cidr, ?string $gateway = null): IpPrefixRecord => app(SavePrefix::class)
        ->create($this->pool, IpPrefix::parse($cidr), $gateway);
});

it('hands out the first free address in the prefix', function (): void {
    $prefix = ($this->prefix)('192.0.2.0/24');

    $first = app(AllocateAddress::class)->handle($prefix);
    $second = app(AllocateAddress::class)->handle($prefix);

    // .0 is the network and it is not handed out.
    expect($first->address)->toBe('192.0.2.1')
        ->and($second->address)->toBe('192.0.2.2');
});

it('steps over an address somebody has already claimed', function (): void {
    $prefix = ($this->prefix)('192.0.2.0/24');

    IpAddressRecord::factory()->inPrefix($prefix)->of('192.0.2.1')->create();
    IpAddressRecord::factory()->inPrefix($prefix)->of('192.0.2.2')
        ->state_(AddressState::Reserved)->create();

    expect(app(AllocateAddress::class)->handle($prefix)->address)->toBe('192.0.2.3');
});

/**
 * A /31 is a point-to-point link: two addresses, no broadcast, and both usable.
 */
it('hands out both addresses of a point to point link', function (): void {
    $prefix = ($this->prefix)('198.51.100.0/31');

    expect(app(AllocateAddress::class)->handle($prefix)->address)->toBe('198.51.100.0')
        ->and(app(AllocateAddress::class)->handle($prefix)->address)->toBe('198.51.100.1');

    expect(fn (): IpAddressRecord => app(AllocateAddress::class)->handle($prefix))
        ->toThrow(AddressingFailed::class, 'no free addresses');
});

it('assigns an address to a service and says who holds it', function (): void {
    $prefix = ($this->prefix)('192.0.2.0/24');
    $address = app(AllocateAddress::class)->handle($prefix);
    $service = Service::factory()->create();

    $assignment = app(AssignAddress::class)->handle($address, $service, 'dedicated IP addon');

    expect($address->fresh()?->state)->toBe(AddressState::Assigned)
        ->and($assignment->holder_id)->toBe($service->id)
        // Copied now, because the service may be renamed or terminated and a
        // history that cannot say who held the address is not a history.
        ->and($assignment->holder_label)->not->toBe('')
        // The seller's row, not the customer's: the address is out of the
        // seller's range.
        ->and($assignment->organization_id)->toBe($this->provider->id);
});

it('refuses to give one address to two holders', function (): void {
    $prefix = ($this->prefix)('192.0.2.0/24');
    $address = app(AllocateAddress::class)->handle($prefix);

    app(AssignAddress::class)->handle($address, Service::factory()->create());

    expect(fn (): IpAssignment => app(AssignAddress::class)->handle($address, Service::factory()->create()))
        ->toThrow(AddressingFailed::class, 'already assigned');
});

/**
 * §5 makes historical ownership mandatory, and this is the shape of it: the row
 * closes, nothing is deleted, and the address does not go straight back out.
 */
it('closes the assignment rather than deleting it, and quarantines the address', function (): void {
    $prefix = ($this->prefix)('192.0.2.0/24');
    $address = app(AllocateAddress::class)->handle($prefix);

    app(AssignAddress::class)->handle($address, Service::factory()->create());
    app(AssignAddress::class)->release($address);

    expect(IpAssignment::query()->count())->toBe(1)
        ->and(IpAssignment::query()->sole()->released_at)->not->toBeNull()
        // Handing a released address straight to somebody else gives them
        // whatever reputation the last holder earned.
        ->and($address->fresh()?->state)->toBe(AddressState::Quarantined);

    // And so it is not offered again.
    expect(app(AllocateAddress::class)->handle($prefix)->address)->toBe('192.0.2.2');
});

it('lets an operator put an address straight back deliberately', function (): void {
    $prefix = ($this->prefix)('192.0.2.0/24');
    $address = app(AllocateAddress::class)->handle($prefix);

    app(AssignAddress::class)->handle($address, Service::factory()->create());
    app(AssignAddress::class)->release($address, quarantine: false);

    expect($address->fresh()?->state)->toBe(AddressState::Available);
});

it('refuses to release what nothing holds', function (): void {
    $prefix = ($this->prefix)('192.0.2.0/24');
    $address = app(AllocateAddress::class)->handle($prefix);

    expect(fn (): IpAssignment => app(AssignAddress::class)->release($address))
        ->toThrow(AddressingFailed::class, 'not assigned');
});

it('refuses a reserved address rather than handing it out', function (): void {
    $prefix = ($this->prefix)('192.0.2.0/24');

    $address = IpAddressRecord::factory()->inPrefix($prefix)->of('192.0.2.9')
        ->state_(AddressState::Reserved)->create();

    expect(fn (): IpAssignment => app(AssignAddress::class)->handle($address, Service::factory()->create()))
        ->toThrow(AddressingFailed::class, 'reserved');
});

it('keeps the address history of a service that has been terminated', function (): void {
    $prefix = ($this->prefix)('192.0.2.0/24');
    $address = app(AllocateAddress::class)->handle($prefix);
    $service = Service::factory()->create();

    app(AssignAddress::class)->handle($address, $service);
    app(AssignAddress::class)->release($address);

    $service->delete();

    // An abuse report arrives weeks late and names an address and a date. The
    // holder is gone; the answer is not.
    expect(IpAssignment::query()->sole()->holder_label)->not->toBe('');
});

/**
 * The lock in `AllocateAddress` is what stops two provisioning jobs handing out
 * one address, and a lock is not something a test can race for honestly — a
 * flaky test is worse than none, because it gets retried until it passes. So
 * the guard underneath it is tested instead: the database itself refuses the
 * second row, whatever the lock did.
 */
it('cannot hold one address twice, whatever the lock did', function (): void {
    $prefix = ($this->prefix)('192.0.2.0/24');

    IpAddressRecord::factory()->inPrefix($prefix)->of('192.0.2.1')->create();

    expect(fn (): IpAddressRecord => IpAddressRecord::factory()
        ->inPrefix($prefix)->of('192.0.2.1')->create())
        ->toThrow(QueryException::class);
});

it('refuses the same network twice', function (): void {
    ($this->prefix)('192.0.2.0/24');

    expect(fn (): IpPrefixRecord => ($this->prefix)('192.0.2.0/24'))
        ->toThrow(AddressingFailed::class, 'overlaps');
});

it('refuses a network that does not belong in the pool', function (): void {
    expect(fn (): IpPrefixRecord => ($this->prefix)('2001:db8::/32'))
        ->toThrow(AddressingFailed::class, 'does not belong');
});

it('refuses a gateway that is not inside the network', function (): void {
    expect(fn (): IpPrefixRecord => ($this->prefix)('192.0.2.0/24', '198.51.100.1'))
        ->toThrow(AddressingFailed::class, 'not inside');
});

/**
 * The tree is worked out rather than typed in, because a parent chosen by hand
 * goes stale the moment a shorter prefix is added above it.
 */
it('parents a subnet under the nearest supernet, whichever order they arrive in', function (): void {
    $eight = ($this->prefix)('10.0.0.0/8');
    $twentyFour = ($this->prefix)('10.1.2.0/24');

    expect($twentyFour->parent_id)->toBe($eight->id);

    // The /16 arrives last and takes the /24 with it.
    $sixteen = ($this->prefix)('10.1.0.0/16');

    expect($sixteen->fresh()?->parent_id)->toBe($eight->id)
        ->and($twentyFour->fresh()?->parent_id)->toBe($sixteen->id);
});

it('keeps the subnets when the supernet is removed', function (): void {
    $eight = ($this->prefix)('10.0.0.0/8');
    $sixteen = ($this->prefix)('10.1.0.0/16');

    app(SavePrefix::class)->delete($sixteen);

    expect(IpPrefixRecord::query()->whereKey($eight->id)->exists())->toBeTrue();
});

it('refuses to delete a prefix that still holds addresses', function (): void {
    $prefix = ($this->prefix)('192.0.2.0/24');
    app(AllocateAddress::class)->handle($prefix);

    expect(fn () => app(SavePrefix::class)->delete($prefix))
        ->toThrow(AddressingFailed::class, 'still holds');
});

/**
 * Two organizations may number their networks identically, because RFC 1918
 * space is everybody's.
 */
it('lets two organizations hold the same private network', function (): void {
    ($this->prefix)('10.0.0.0/8');

    $reseller = Organization::factory()->reseller($this->provider)->create();

    $theirs = IpPool::factory()->create([
        'organization_id' => $reseller->id,
        'family' => IpFamily::V4->value,
    ]);

    expect(app(SavePrefix::class)->create($theirs, IpPrefix::parse('10.0.0.0/8'))->cidr)
        ->toBe('10.0.0.0/8');
});
