<?php

declare(strict_types=1);

use App\Application\Access\SyncPermissions;
use App\Application\Network\AssignAddress;
use App\Application\Network\RecordDdosEvent;
use App\Domain\Access\PermissionRegistry;
use App\Domain\Access\SystemRole;
use App\Domain\Infrastructure\Network\DdosAttack;
use App\Domain\Infrastructure\Network\DdosVector;
use App\Domain\Network\AddressState;
use App\Domain\Network\IpAddress;
use App\Domain\Organizations\OrganizationType;
use App\Infrastructure\Identity\Models\StaffUser;
use App\Infrastructure\Network\Models\DdosEvent;
use App\Infrastructure\Network\Models\IpAddressRecord;
use App\Infrastructure\Network\Models\IpAssignment;
use App\Infrastructure\Network\Models\IpPool;
use App\Infrastructure\Network\Models\IpPrefixRecord;
use App\Infrastructure\Organizations\Models\Organization;
use App\Infrastructure\Provisioning\Models\Service;
use App\Support\Organizations\OrganizationContext;
use Carbon\CarbonImmutable;
use Database\Seeders\ProviderOrganizationSeeder;
use Database\Seeders\SystemRoleSeeder;

/**
 * Attacks, and whose they were (`phase-c-plan.md` §7).
 *
 * **The attribution is the whole value.** Every scrubbing vendor can say an
 * address was hit with 40 Gbps of NTP reflection; only this platform can say
 * whose hosting account was on that address *at the time*. "At the time" is
 * the part that needs `ip_assignments` rather than the current holder, and it
 * is what §5 made that table append-only for — an attack last Tuesday on an
 * address since handed to somebody else must not be attributed to its new
 * holder.
 *
 * The flow series stays outside, which is §7's own instruction and §14's
 * rule: a NetFlow collector inside a billing database is a time-series store
 * nobody sized.
 */
beforeEach(function (): void {
    $this->seed(ProviderOrganizationSeeder::class);
    app(SyncPermissions::class)->handle(app(PermissionRegistry::class));
    $this->seed(SystemRoleSeeder::class);

    $this->provider = Organization::query()
        ->where('type', OrganizationType::Provider->value)
        ->firstOrFail();

    app(OrganizationContext::class)->set($this->provider->id);

    $this->admin = StaffUser::factory()->create();
    $this->admin->assignRole(SystemRole::Administrator);
    $this->admin = $this->admin->fresh();

    $pool = IpPool::factory()->create(['organization_id' => $this->provider->id]);
    $this->prefix = IpPrefixRecord::factory()->inPool($pool)->of('198.51.100.0/24')->create();
});

/** An address row, the way something acting on one creates it. */
function ddosAddress(string $text = '198.51.100.7'): IpAddressRecord
{
    $record = IpAddressRecord::within(
        test()->prefix,
        IpAddress::parse($text),
        // Available, not reserved: a reserved address is one somebody set
        // aside and `AssignAddress` refuses it by name.
        AddressState::Available,
    );

    $record->save();

    return $record;
}

function attack(array $overrides = []): DdosAttack
{
    return new DdosAttack(
        reference: $overrides['reference'] ?? 'atk-001',
        target: $overrides['target'] ?? '198.51.100.7',
        startedAt: $overrides['startedAt'] ?? CarbonImmutable::now()->subMinutes(20),
        endedAt: $overrides['endedAt'] ?? null,
        peakGbps: $overrides['peakGbps'] ?? 41.2,
        peakMpps: $overrides['peakMpps'] ?? 6.4,
        vectors: $overrides['vectors'] ?? [DdosVector::NtpReflection],
        mitigation: $overrides['mitigation'] ?? 'Diverted to scrubbing',
    );
}

it('attributes an attack to whoever held the address at the time', function (): void {
    $address = ddosAddress();

    $past = Service::factory()->create(['organization_id' => $this->provider->id]);
    $present = Service::factory()->create(['organization_id' => $this->provider->id]);

    // Held by one service last week, released, and handed to another since.
    IpAssignment::query()->create([
        'organization_id' => $this->provider->id,
        'ip_address_id' => $address->id,
        'holder_type' => Service::class,
        'holder_id' => $past->id,
        'holder_label' => $past->name,
        'assigned_at' => CarbonImmutable::now()->subDays(30),
        'released_at' => CarbonImmutable::now()->subDays(2),
    ]);

    IpAssignment::query()->create([
        'organization_id' => $this->provider->id,
        'ip_address_id' => $address->id,
        'holder_type' => Service::class,
        'holder_id' => $present->id,
        'holder_label' => $present->name,
        'assigned_at' => CarbonImmutable::now()->subDay(),
    ]);

    // The attack was a week ago, while the first service held it.
    $event = app(RecordDdosEvent::class)->handle(
        $this->provider->id,
        'scrubbing',
        attack(['startedAt' => CarbonImmutable::now()->subDays(7)]),
    );

    // The answer an abuse report or a credit request actually needs — and the
    // one a lookup of the *present* assignment would get wrong.
    expect($event->service_id)->toBe($past->id)
        ->and($event->customer_id)->toBe($past->customer_id);
});

/**
 * An attack on an address nobody held is a finding — a misconfigured
 * scrubber, a range nobody recorded, somebody else's address reported to us —
 * and throwing the row away would be throwing the finding away.
 */
it('keeps an attack nobody was behind', function (): void {
    $event = app(RecordDdosEvent::class)->handle($this->provider->id, 'scrubbing', attack());

    expect($event->exists)->toBeTrue()
        ->and($event->customer_id)->toBeNull()
        ->and($event->service_id)->toBeNull()
        ->and($event->target_address)->toBe('198.51.100.7');
});

/**
 * The sweep runs every few minutes and an attack lasting an hour is reported
 * again each time with a later end and a higher peak. The row is updated
 * rather than duplicated — rule 9 in one line.
 */
it('updates a running attack rather than writing it twice', function (): void {
    app(RecordDdosEvent::class)->handle($this->provider->id, 'scrubbing', attack([
        'endedAt' => null,
        'peakGbps' => 12.0,
    ]));

    app(RecordDdosEvent::class)->handle($this->provider->id, 'scrubbing', attack([
        'endedAt' => CarbonImmutable::now()->subMinutes(2),
        'peakGbps' => 41.2,
    ]));

    $event = DdosEvent::query()->sole();

    expect((float) $event->peak_gbps)->toBe(41.2)
        ->and($event->isRunning())->toBeFalse()
        ->and($event->durationSeconds())->toBeGreaterThan(0);
});

/**
 * Two spellings of one IPv6 address are one address. A text comparison would
 * attribute neither, which is the bug that makes an abuse report unanswerable.
 */
it('matches an address however the vendor spelled it', function (): void {
    $pool = IpPool::factory()->create(['organization_id' => $this->provider->id]);
    $prefix = IpPrefixRecord::factory()->inPool($pool)->of('2001:db8::/64')->create();

    $address = IpAddressRecord::within(
        $prefix,
        IpAddress::parse('2001:db8::1'),
        AddressState::Available,
    );
    $address->save();

    $service = Service::factory()->create(['organization_id' => $this->provider->id]);

    IpAssignment::query()->create([
        'organization_id' => $this->provider->id,
        'ip_address_id' => $address->id,
        'holder_type' => Service::class,
        'holder_id' => $service->id,
        'holder_label' => $service->name,
        'assigned_at' => CarbonImmutable::now()->subDay(),
    ]);

    $event = app(RecordDdosEvent::class)->handle($this->provider->id, 'scrubbing', attack([
        'reference' => 'atk-v6',
        // The same address, written the long way.
        'target' => '2001:0db8:0000:0000:0000:0000:0000:0001',
    ]));

    expect($event->service_id)->toBe($service->id)
        // Stored as this platform spells it, so one address is one string.
        ->and($event->target_address)->toBe('2001:db8::1');
});

/** A target this platform cannot parse is still a record of an attack. */
it('keeps an unreadable target verbatim', function (): void {
    $event = app(RecordDdosEvent::class)->handle($this->provider->id, 'scrubbing', attack([
        'reference' => 'atk-junk',
        'target' => 'not-an-address',
    ]));

    expect($event->target_address)->toBe('not-an-address')
        ->and($event->ip_address_id)->toBeNull();
});

it('renders the screen with what was behind the addresses', function (): void {
    $address = ddosAddress();
    $service = Service::factory()->create([
        'organization_id' => $this->provider->id,
        'recurring_minor' => 4_990,
        'currency_code' => 'EUR',
    ]);

    IpAssignment::query()->create([
        'organization_id' => $this->provider->id,
        'ip_address_id' => $address->id,
        'holder_type' => Service::class,
        'holder_id' => $service->id,
        'holder_label' => $service->name,
        'assigned_at' => CarbonImmutable::now()->subDays(10),
    ]);

    app(RecordDdosEvent::class)->handle($this->provider->id, 'scrubbing', attack());

    $this->actingAs($this->admin, 'staff')
        ->get('/admin/network/attacks')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Admin/Network/Attacks')
            ->has('events.data', 1)
            ->where('impact.services', 1)
            ->where('impact.customers', 1)
            // Money is a list, never a number: a total across currencies is a
            // figure that means nothing and is the one somebody would quote.
            ->has('impact.recurring', 1)
            ->where('impact.recurring.0.currency', 'EUR'));
});

it('refuses the screen to somebody without the permission', function (): void {
    $agent = StaffUser::factory()->create();

    $this->actingAs($agent->fresh(), 'staff')
        ->get('/admin/network/attacks')
        ->assertForbidden();
});

/** Support holds it: an abuse report and "my site was down" arrive together. */
it('lets support read it', function (): void {
    $agent = StaffUser::factory()->create();
    $agent->assignRole(SystemRole::Support);

    $this->actingAs($agent->fresh(), 'staff')
        ->get('/admin/network/attacks')
        ->assertOk();
});

/**
 * `AssignAddress` is the path an operator uses, and an event recorded after
 * it must find the same holder — otherwise the attribution works only for
 * rows a test wrote by hand.
 */
it('finds a holder that was assigned through the use case', function (): void {
    $address = ddosAddress('198.51.100.9');
    $service = Service::factory()->create(['organization_id' => $this->provider->id]);

    app(AssignAddress::class)->handle($address, $service);

    $event = app(RecordDdosEvent::class)->handle($this->provider->id, 'scrubbing', attack([
        'reference' => 'atk-002',
        'target' => '198.51.100.9',
        'startedAt' => CarbonImmutable::now(),
    ]));

    expect($event->service_id)->toBe($service->id);
});
