<?php

declare(strict_types=1);

use App\Application\Access\SyncPermissions;
use App\Application\Security\InspectZone;
use App\Application\Security\RecordZoneFindings;
use App\Domain\Access\PermissionRegistry;
use App\Domain\Access\SystemRole;
use App\Domain\Infrastructure\Dns\DnsRecord;
use App\Domain\Infrastructure\Dns\DnsRecordType;
use App\Domain\Infrastructure\Dns\DnsZone;
use App\Domain\Organizations\OrganizationType;
use App\Domain\Security\FindingSeverity;
use App\Domain\Security\ZoneCheck;
use App\Infrastructure\Domains\Models\Domain;
use App\Infrastructure\Identity\Models\StaffUser;
use App\Infrastructure\Organizations\Models\Organization;
use App\Infrastructure\Security\Models\ZoneFinding;
use App\Support\Organizations\OrganizationContext;
use Carbon\CarbonImmutable;
use Database\Seeders\ProviderOrganizationSeeder;
use Database\Seeders\SystemRoleSeeder;

/**
 * Zone health (§8).
 *
 * **Every check is an RFC, not an opinion**, and that is what makes them
 * core's rather than an adapter's: two SPF records is RFC 7208 §3.2, `+all`
 * says anyone may send as you, one nameserver is RFC 1034 §4.1. Core does not
 * grade a DMARC policy, does not decide whether a domain ought to have mail
 * and has no views about TTLs — a findings list full of things that are fine
 * is one an operator stops reading, and then misses the `+all`.
 *
 * **`InspectZone` is pure**, which is the only way these rules can be trusted
 * before any real provider has ever answered: a zone typed out in a test is
 * the whole input.
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

    $this->inspector = app(InspectZone::class);
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

/**
 * A zone that passes every check, which the tests then break one thing at a
 * time. Building from a good zone rather than an empty one is what stops a
 * test passing because of a finding it never meant to assert.
 *
 * @param  list<DnsRecord>  $extra
 */
function healthyZone(array $extra = [], ?bool $dnssec = true): DnsZone
{
    return new DnsZone(
        name: 'example.com',
        records: [
            new DnsRecord('example.com', DnsRecordType::Ns, 'ns1.example.net.'),
            new DnsRecord('example.com', DnsRecordType::Ns, 'ns2.example.net.'),
            new DnsRecord('example.com', DnsRecordType::Mx, '10 mail.example.net.'),
            new DnsRecord('example.com', DnsRecordType::Txt, 'v=spf1 include:example.net -all'),
            new DnsRecord('_dmarc.example.com', DnsRecordType::Txt, 'v=DMARC1; p=reject; rua=mailto:d@example.com'),
            ...$extra,
        ],
        dnssec: $dnssec,
    );
}

it('says nothing about a zone that is in order', function (): void {
    expect($this->inspector->handle(healthyZone()))->toBe([]);
});

/** RFC 7208 §3.2: a resolver that finds two gives up on both. */
it('finds two SPF records', function (): void {
    $findings = $this->inspector->handle(healthyZone([
        new DnsRecord('example.com', DnsRecordType::Txt, 'v=spf1 include:other.example -all'),
    ]));

    expect($findings)->toContain(ZoneCheck::SpfDuplicate)
        ->and(ZoneCheck::SpfDuplicate->severity())->toBe(FindingSeverity::Warning);
});

/**
 * The bare `all` is the one people write by accident, because `+` is the
 * default qualifier and reads like it means nothing.
 */
it('finds an SPF record that lets anyone send', function (): void {
    foreach (['v=spf1 +all', 'v=spf1 include:example.net all'] as $record) {
        $zone = new DnsZone(
            name: 'example.com',
            records: [
                new DnsRecord('example.com', DnsRecordType::Ns, 'ns1.example.net.'),
                new DnsRecord('example.com', DnsRecordType::Ns, 'ns2.example.net.'),
                new DnsRecord('example.com', DnsRecordType::Mx, '10 mail.example.net.'),
                new DnsRecord('example.com', DnsRecordType::Txt, $record),
                new DnsRecord('_dmarc.example.com', DnsRecordType::Txt, 'v=DMARC1; p=reject'),
            ],
            dnssec: true,
        );

        expect($this->inspector->handle($zone))->toContain(ZoneCheck::SpfPermissive);
    }
});

/** `-all` and `~all` are the whole point of SPF and must not be flagged. */
it('does not flag a strict or soft-fail SPF record', function (): void {
    foreach (['v=spf1 -all', 'v=spf1 ~all', 'v=spf1 include:x.example -all'] as $record) {
        $zone = new DnsZone(
            name: 'example.com',
            records: [
                new DnsRecord('example.com', DnsRecordType::Ns, 'a.example.net.'),
                new DnsRecord('example.com', DnsRecordType::Ns, 'b.example.net.'),
                new DnsRecord('example.com', DnsRecordType::Mx, '10 mail.example.net.'),
                new DnsRecord('example.com', DnsRecordType::Txt, $record),
                new DnsRecord('_dmarc.example.com', DnsRecordType::Txt, 'v=DMARC1; p=quarantine'),
            ],
            dnssec: true,
        );

        expect($this->inspector->handle($zone))->toBe([]);
    }
});

/**
 * A provider that round-trips through a zone file hands back quoted values,
 * and a check that missed the quoted form would report every zone on that
 * provider as having no SPF.
 */
it('reads a TXT record whether or not the provider quotes it', function (): void {
    $zone = new DnsZone(
        name: 'example.com',
        records: [
            new DnsRecord('example.com', DnsRecordType::Ns, 'a.example.net.'),
            new DnsRecord('example.com', DnsRecordType::Ns, 'b.example.net.'),
            new DnsRecord('example.com', DnsRecordType::Mx, '10 mail.example.net.'),
            new DnsRecord('example.com', DnsRecordType::Txt, '"v=spf1 -all"'),
            new DnsRecord('_dmarc.example.com', DnsRecordType::Txt, '"v=DMARC1; p=reject"'),
        ],
        dnssec: true,
    );

    expect($this->inspector->handle($zone))->toBe([]);
});

/** `p=none` is monitoring, which most people deploy on purpose. */
it('calls a monitoring DMARC policy information rather than a fault', function (): void {
    $zone = new DnsZone(
        name: 'example.com',
        records: [
            new DnsRecord('example.com', DnsRecordType::Ns, 'a.example.net.'),
            new DnsRecord('example.com', DnsRecordType::Ns, 'b.example.net.'),
            new DnsRecord('example.com', DnsRecordType::Mx, '10 mail.example.net.'),
            new DnsRecord('example.com', DnsRecordType::Txt, 'v=spf1 -all'),
            new DnsRecord('_dmarc.example.com', DnsRecordType::Txt, 'v=DMARC1; p=none; rua=mailto:d@example.com'),
        ],
        dnssec: true,
    );

    expect($this->inspector->handle($zone))->toBe([ZoneCheck::DmarcMonitorOnly])
        ->and(ZoneCheck::DmarcMonitorOnly->severity())->toBe(FindingSeverity::Information);
});

/** RFC 1034 §4.1, and a provider listing one twice has not given it two. */
it('finds a zone with only one nameserver, however it is listed', function (): void {
    $zone = new DnsZone(
        name: 'example.com',
        records: [
            new DnsRecord('example.com', DnsRecordType::Ns, 'ns1.example.net.'),
            // The same one again, with a different spelling.
            new DnsRecord('example.com', DnsRecordType::Ns, 'NS1.example.net'),
            new DnsRecord('example.com', DnsRecordType::Mx, '10 mail.example.net.'),
            new DnsRecord('example.com', DnsRecordType::Txt, 'v=spf1 -all'),
            new DnsRecord('_dmarc.example.com', DnsRecordType::Txt, 'v=DMARC1; p=reject'),
        ],
        dnssec: true,
    );

    expect($this->inspector->handle($zone))->toBe([ZoneCheck::NsTooFew]);
});

/**
 * A null means the provider could not say, which is not the same as "off".
 * Reporting an unsigned zone there would be telling an operator something
 * this platform does not know.
 */
it('says nothing about DNSSEC when the provider could not say', function (): void {
    expect($this->inspector->handle(healthyZone(dnssec: null)))->toBe([])
        ->and($this->inspector->handle(healthyZone(dnssec: false)))->toBe([ZoneCheck::DnssecOff]);
});

it('raises a finding, keeps it while it is true and clears it when fixed', function (): void {
    $domain = app(OrganizationContext::class)->withoutBoundary(
        static fn (): Domain => Domain::factory()->create(['name' => 'example.com']),
    );

    $recorder = app(RecordZoneFindings::class);

    $first = $recorder->handle($domain, 'dns-a', [ZoneCheck::SpfPermissive, ZoneCheck::MxMissing]);

    expect($first['raised'])->toBe(2)
        ->and(ZoneFinding::query()->open()->count())->toBe(2);

    // Still true. One row, a later `last_seen_at`, nothing raised.
    $second = $recorder->handle($domain, 'dns-a', [ZoneCheck::SpfPermissive, ZoneCheck::MxMissing]);

    expect($second['raised'])->toBe(0)
        ->and($second['kept'])->toBe(2)
        ->and(ZoneFinding::query()->count())->toBe(2);

    // Somebody fixed the SPF record.
    $third = $recorder->handle($domain, 'dns-a', [ZoneCheck::MxMissing]);

    expect($third['cleared'])->toBe(1)
        ->and(ZoneFinding::query()->open()->count())->toBe(1)
        // Kept rather than deleted: "when did we fix that" is a question.
        ->and(ZoneFinding::query()->count())->toBe(2);

    // And it can be raised again, which the unique index has to allow.
    $fourth = $recorder->handle($domain, 'dns-a', [ZoneCheck::SpfPermissive, ZoneCheck::MxMissing]);

    expect($fourth['raised'])->toBe(1)
        ->and(ZoneFinding::query()->count())->toBe(3);
});

/** Two providers holding one zone is a real configuration during a migration. */
it('keeps one source’s findings apart from another’s', function (): void {
    $domain = app(OrganizationContext::class)->withoutBoundary(
        static fn (): Domain => Domain::factory()->create(['name' => 'example.com']),
    );

    $recorder = app(RecordZoneFindings::class);

    $recorder->handle($domain, 'old-provider', [ZoneCheck::SpfPermissive]);
    $recorder->handle($domain, 'new-provider', [ZoneCheck::SpfPermissive]);

    expect(ZoneFinding::query()->open()->count())->toBe(2);

    // The new provider is fixed; the old one still says it.
    $recorder->handle($domain, 'new-provider', []);

    expect(ZoneFinding::query()->open()->count())->toBe(1)
        ->and(ZoneFinding::query()->open()->sole()->source)->toBe('old-provider');
});

it('drives the screen, warnings first', function (): void {
    $domain = app(OrganizationContext::class)->withoutBoundary(
        static fn (): Domain => Domain::factory()->create(['name' => 'example.com']),
    );

    ZoneFinding::factory()->of(ZoneCheck::DmarcMissing)->create([
        'organization_id' => $this->provider->id,
        'domain_id' => $domain->id,
        'severity' => FindingSeverity::Warning,
    ]);

    ZoneFinding::factory()->of(ZoneCheck::SpfPermissive)->create([
        'organization_id' => $this->provider->id,
        'domain_id' => $domain->id,
        'severity' => FindingSeverity::Warning,
    ]);

    $this->actingAs($this->admin, 'staff')
        ->get('/admin/security/dns')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Admin/Security/ZoneHealth')
            ->has('findings.data', 2)
            ->where('counts.warnings', 2)
            // Every row carries what to do about it.
            ->where('findings.data.0.detail', fn (string $detail): bool => $detail !== '')
            ->has('findings.links'));
});

it('refuses somebody without the permission', function (): void {
    $nobody = StaffUser::factory()->create();

    $this->actingAs($nobody->fresh(), 'staff')
        ->get('/admin/security/dns')
        ->assertForbidden();
});

/** Every check an operator reads is named, in both languages. */
it('names every check and its fix in both languages', function (): void {
    foreach (['en', 'tr'] as $locale) {
        app()->setLocale($locale);

        foreach (ZoneCheck::cases() as $check) {
            expect(__($check->labelKey()))->not->toBe($check->labelKey())
                ->and(__($check->detailKey()))->not->toBe($check->detailKey());
        }
    }

    app()->setLocale('en');
});
