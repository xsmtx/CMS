<?php

declare(strict_types=1);

use App\Application\Access\SyncPermissions;
use App\Application\Automation\TaskRegistry;
use App\Application\Security\RecordCertificates;
use App\Domain\Access\PermissionRegistry;
use App\Domain\Access\SystemRole;
use App\Domain\Automation\AutomationTask;
use App\Domain\Infrastructure\Certificates\DeployedCertificate;
use App\Domain\Organizations\OrganizationType;
use App\Domain\Reliability\AlertComparison;
use App\Domain\Reliability\AlertSeverity;
use App\Domain\Reliability\AlertState;
use App\Domain\Reliability\AlertSubject;
use App\Infrastructure\Domains\Models\Domain;
use App\Infrastructure\Identity\Models\StaffUser;
use App\Infrastructure\Organizations\Models\Organization;
use App\Infrastructure\Reliability\Models\Alert;
use App\Infrastructure\Reliability\Models\AlertRule;
use App\Infrastructure\Security\Models\Certificate;
use App\Support\Organizations\OrganizationContext;
use Carbon\CarbonImmutable;
use Database\Seeders\ProviderOrganizationSeeder;
use Database\Seeders\SystemRoleSeeder;

/**
 * The certificate fleet (§8).
 *
 * **Discovered, never issued.** Core reads what is deployed and answers the
 * questions that need no private key: what expires soon, what is served under
 * a name it does not cover, whose customer is affected.
 *
 * **The fingerprint is the identity.** One name is served by four
 * certificates over a year, so a row keyed on the name would collapse the
 * renewals into each other — and an alert keyed on it would look like the
 * same alert clearing and reopening at every renewal.
 *
 * **Expiry is a rule, not a constant.** Thirty days is right for a business
 * renewing by hand and absurd for one on ACME with a fortnight's lifetime, so
 * the threshold is the operator's and the platform ships none.
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
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

/**
 * @param  list<string>  $names
 */
function deployed(string $fingerprint, array $names, int $daysLeft = 60, ?string $nodeKey = null): DeployedCertificate
{
    return new DeployedCertificate(
        fingerprint: $fingerprint,
        commonName: $names[0],
        subjectAlternativeNames: $names,
        issuer: "Let's Encrypt R3",
        notBefore: CarbonImmutable::now()->subDays(30),
        notAfter: CarbonImmutable::now()->addDays($daysLeft),
        serial: '01ab',
        chainOk: true,
        nodeKey: $nodeKey,
    );
}

it('records what an adapter found, keyed on the fingerprint', function (): void {
    app(RecordCertificates::class)->handle($this->provider->id, 'panel', [
        deployed('aa11', ['example.com', 'www.example.com']),
        deployed('bb22', ['other.example.net']),
    ]);

    expect(Certificate::query()->count())->toBe(2);

    // The same sweep again is the ordinary case and writes no new rows.
    $outcome = app(RecordCertificates::class)->handle($this->provider->id, 'panel', [
        deployed('aa11', ['example.com', 'www.example.com']),
        deployed('bb22', ['other.example.net']),
    ]);

    expect(Certificate::query()->count())->toBe(2)
        ->and($outcome['retired'])->toBe(0);
});

/**
 * A certificate the adapter stops naming has been replaced or removed. The
 * row is retired rather than deleted: "what was on that machine in March" is
 * a question somebody asks after an outage.
 */
it('retires what has stopped being served, and keeps the row', function (): void {
    app(RecordCertificates::class)->handle($this->provider->id, 'panel', [
        deployed('aa11', ['example.com']),
        deployed('bb22', ['other.example.net']),
    ]);

    $outcome = app(RecordCertificates::class)->handle($this->provider->id, 'panel', [
        deployed('aa11', ['example.com']),
    ]);

    expect($outcome['retired'])->toBe(1)
        ->and(Certificate::query()->count())->toBe(2)
        ->and(Certificate::query()->live()->count())->toBe(1)
        ->and(Certificate::query()->where('fingerprint', 'bb22')->sole()->retired_at)->not->toBeNull();
});

/** Retiring by organization would take a second adapter's fleet with it. */
it('retires only what its own source wrote', function (): void {
    app(RecordCertificates::class)->handle($this->provider->id, 'panel', [deployed('aa11', ['a.example.com'])]);
    app(RecordCertificates::class)->handle($this->provider->id, 'probe', [deployed('bb22', ['b.example.com'])]);

    // The panel now reports nothing at all.
    app(RecordCertificates::class)->handle($this->provider->id, 'panel', []);

    expect(Certificate::query()->live()->count())->toBe(1)
        ->and(Certificate::query()->live()->sole()->source)->toBe('probe');
});

it('attributes a certificate to the customer who holds the domain', function (): void {
    $domain = app(OrganizationContext::class)->withoutBoundary(
        static fn (): Domain => Domain::factory()->create(['name' => 'example.com']),
    );

    app(RecordCertificates::class)->handle($this->provider->id, 'panel', [
        // The nearest name this installation holds, not the exact one.
        deployed('aa11', ['shop.example.com', 'www.shop.example.com']),
    ]);

    $certificate = Certificate::query()->sole();

    expect($certificate->domain_id)->toBe($domain->id)
        ->and($certificate->customer_id)->toBe($domain->customer_id);
});

/**
 * A wildcard covering four customers belongs to none of them. Ambiguity is
 * refused rather than resolved — a certificate attached to the wrong customer
 * is worse than one attached to nobody.
 */
it('attributes nothing when the names point at two customers', function (): void {
    app(OrganizationContext::class)->withoutBoundary(static function (): void {
        Domain::factory()->create(['name' => 'first.example']);
        Domain::factory()->create(['name' => 'second.example']);
    });

    app(RecordCertificates::class)->handle($this->provider->id, 'panel', [
        deployed('aa11', ['www.first.example', 'www.second.example']),
    ]);

    expect(Certificate::query()->sole()->customer_id)->toBeNull();
});

/**
 * A wildcard covers one label and no more. Getting this wrong in the lenient
 * direction would have the platform reporting a name as covered when a
 * browser would refuse it, which is worse than no answer.
 */
it('knows what a wildcard actually covers', function (): void {
    $certificate = Certificate::factory()
        ->covering(['*.example.com'])
        ->create(['organization_id' => $this->provider->id]);

    expect($certificate->covers('a.example.com'))->toBeTrue()
        ->and($certificate->covers('A.Example.Com'))->toBeTrue()
        // One label, not two.
        ->and($certificate->covers('a.b.example.com'))->toBeFalse()
        // And not the bare name, which is the mistake everybody makes.
        ->and($certificate->covers('example.com'))->toBeFalse();
});

it('raises an alert when a certificate is inside the operator’s threshold', function (): void {
    Certificate::factory()
        ->expiringIn(9)
        ->create(['organization_id' => $this->provider->id, 'common_name' => 'soon.example.com']);

    Certificate::factory()
        ->expiringIn(90)
        ->create(['organization_id' => $this->provider->id, 'common_name' => 'fine.example.com']);

    AlertRule::factory()->create([
        'organization_id' => $this->provider->id,
        'subject' => AlertSubject::CertificateExpiry,
        'target' => null,
        'comparison' => AlertComparison::Below,
        // Fourteen days, which is the operator's number and not the
        // platform's — core ships no rules at all.
        'threshold_ppm' => 14_000_000,
        'for_minutes' => 0,
        'severity' => AlertSeverity::Critical,
        'enabled' => true,
        'notify' => false,
    ]);

    app(TaskRegistry::class)->resolve(AutomationTask::Alerts)->handle();

    $alert = Alert::query()->sole();

    expect($alert->state)->toBe(AlertState::Raised)
        ->and($alert->subject_label)->toBe('soon.example.com');
});

/**
 * A certificate that lapsed last night is the one somebody most needs to hear
 * about, so "below 14" has to catch "minus 3".
 */
it('still alerts on one that has already expired', function (): void {
    Certificate::factory()
        ->expired()
        ->create(['organization_id' => $this->provider->id, 'common_name' => 'lapsed.example.com']);

    AlertRule::factory()->create([
        'organization_id' => $this->provider->id,
        'subject' => AlertSubject::CertificateExpiry,
        'target' => null,
        'comparison' => AlertComparison::Below,
        'threshold_ppm' => 14_000_000,
        'for_minutes' => 0,
        'severity' => AlertSeverity::Emergency,
        'enabled' => true,
        'notify' => false,
    ]);

    app(TaskRegistry::class)->resolve(AutomationTask::Alerts)->handle();

    expect(Alert::query()->sole()->subject_label)->toBe('lapsed.example.com');
});

/** A retired certificate is not something anybody needs to act on. */
it('does not alert on one that is no longer served', function (): void {
    Certificate::factory()
        ->expired()
        ->retired()
        ->create(['organization_id' => $this->provider->id]);

    AlertRule::factory()->create([
        'organization_id' => $this->provider->id,
        'subject' => AlertSubject::CertificateExpiry,
        'target' => null,
        'comparison' => AlertComparison::Below,
        'threshold_ppm' => 14_000_000,
        'for_minutes' => 0,
        'severity' => AlertSeverity::Critical,
        'enabled' => true,
        'notify' => false,
    ]);

    app(TaskRegistry::class)->resolve(AutomationTask::Alerts)->handle();

    expect(Alert::query()->count())->toBe(0);
});

it('drives the screen', function (): void {
    Certificate::factory()->expiringIn(5)->create(['organization_id' => $this->provider->id]);
    Certificate::factory()->expiringIn(200)->create(['organization_id' => $this->provider->id]);
    Certificate::factory()->expired()->create(['organization_id' => $this->provider->id]);

    $this->actingAs($this->admin, 'staff')
        ->get('/admin/security/certificates')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Admin/Security/Certificates')
            ->has('certificates.data', 3)
            // Soonest first: a fleet is read in the order things break.
            ->where('certificates.data.0.state', 'expired')
            ->where('certificates.data.1.state', 'expiring')
            ->where('certificates.data.2.state', 'healthy')
            ->where('counts.expired', 1)
            ->where('counts.soon', 1)
            ->where('counts.healthy', 1)
            ->has('certificates.links'));
});

it('refuses somebody without the permission', function (): void {
    $nobody = StaffUser::factory()->create();

    $this->actingAs($nobody->fresh(), 'staff')
        ->get('/admin/security/certificates')
        ->assertForbidden();
});
