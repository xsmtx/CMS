<?php

declare(strict_types=1);

use App\Application\Access\SyncPermissions;
use App\Application\Automation\TaskRegistry;
use App\Application\Network\AssignAddress;
use App\Application\Security\AbuseCases;
use App\Domain\Access\PermissionRegistry;
use App\Domain\Access\SystemRole;
use App\Domain\Automation\AutomationTask;
use App\Domain\Network\AddressState;
use App\Domain\Network\IpAddress;
use App\Domain\Organizations\OrganizationType;
use App\Domain\Provisioning\ServiceStatus;
use App\Domain\Reliability\AlertSeverity;
use App\Domain\Security\AbuseAction;
use App\Domain\Security\AbuseActionState;
use App\Domain\Security\AbuseKind;
use App\Domain\Security\AbuseState;
use App\Domain\Security\EvidenceKind;
use App\Domain\Security\Exceptions\AbuseRefused;
use App\Http\Middleware\RequireRecentAuthentication;
use App\Infrastructure\Crm\Models\Customer;
use App\Infrastructure\Identity\Models\StaffUser;
use App\Infrastructure\Network\Models\IpAddressRecord;
use App\Infrastructure\Network\Models\IpPool;
use App\Infrastructure\Network\Models\IpPrefixRecord;
use App\Infrastructure\Organizations\Models\Organization;
use App\Infrastructure\Provisioning\Models\Service;
use App\Infrastructure\Security\Models\AbuseActionRecord;
use App\Infrastructure\Security\Models\AbuseCase;
use App\Infrastructure\Security\Models\AbuseEvidence;
use App\Support\Organizations\OrganizationContext;
use Carbon\CarbonImmutable;
use Database\Seeders\ProviderOrganizationSeeder;
use Database\Seeders\SystemRoleSeeder;

/**
 * The abuse desk (§13).
 *
 * **The attribution rule is the one that matters.** A complaint about an
 * address last Tuesday belongs to whoever held it last Tuesday; attributing
 * it to today's holder is how an innocent customer is suspended for somebody
 * else's spam, and it is the single worst mistake this family can make.
 * `ip_assignments` is append-only for exactly this, and the first test here
 * hands an address from one customer to another and asserts the complaint
 * still lands on the first.
 *
 * **Nothing is automatic.** Every guarded action is a person pressing a
 * button, and three of the four have no seam in this product — they land in
 * `manual`, which is a real outcome and appears as work somebody still has to
 * do.
 *
 * **Evidence is forgotten.** The retention sweep is the one task in this
 * product whose job is to delete, and it deletes the reference without
 * touching the case, the timeline or the decision.
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

/** A customer with one active service. */
function abuseCustomer(string $company): Customer
{
    return app(OrganizationContext::class)->withoutBoundary(static function () use ($company): Customer {
        $customer = Customer::factory()->create(['company_name' => $company]);

        Service::factory()->create([
            'organization_id' => $customer->organization_id,
            'customer_id' => $customer->id,
            'status' => ServiceStatus::Active->value,
        ]);

        return $customer;
    });
}

/** An address inside a prefix this installation records. */
function abuseAddress(Organization $provider, string $cidr, string $address): IpAddressRecord
{
    $pool = IpPool::factory()->create(['organization_id' => $provider->id]);
    $prefix = IpPrefixRecord::factory()->inPool($pool)->of($cidr)->create();

    $record = IpAddressRecord::within(
        $prefix,
        IpAddress::parse($address),
        // Available, not reserved: `AssignAddress` refuses a reserved one by
        // name, which is the whole point of that state.
        AddressState::Available,
    );

    $record->save();

    return $record;
}

it('attributes a complaint to whoever held the address at the time', function (): void {
    CarbonImmutable::setTestNow('2026-09-20 09:00:00');

    $first = abuseCustomer('The one who spammed');
    $second = abuseCustomer('The innocent one');

    $record = abuseAddress($this->provider, '198.51.100.0/24', '198.51.100.7');
    $assign = app(AssignAddress::class);

    // Held by the first customer's service all of last week.
    $assign->handle($record, $first->services()->sole());

    CarbonImmutable::setTestNow('2026-09-25 09:00:00');
    $assign->release($record, quarantine: false);
    $assign->handle($record->fresh(), $second->services()->sole());

    CarbonImmutable::setTestNow('2026-09-27 09:00:00');

    // A complaint that arrives today about something that happened on the
    // 21st. The address is now somebody else's.
    $case = app(AbuseCases::class)->open(
        organizationId: $this->provider->id,
        kind: AbuseKind::Spam,
        summary: 'Outbound spam from 198.51.100.7',
        severity: AlertSeverity::Critical,
        subjectType: 'ip',
        subjectValue: '198.51.100.7',
        occurredAt: CarbonImmutable::parse('2026-09-21 14:00:00'),
        actor: $this->admin,
    );

    expect($case->customer_id)->toBe($first->id)
        ->and($case->customer_id)->not->toBe($second->id);
});

/** Any spelling of an address is the same address. */
it('matches an address on its bytes rather than its text', function (): void {
    $customer = abuseCustomer('Somebody');
    $record = abuseAddress($this->provider, '2001:db8::/64', '2001:db8::1');

    app(AssignAddress::class)->handle($record, $customer->services()->sole());

    $case = app(AbuseCases::class)->open(
        organizationId: $this->provider->id,
        kind: AbuseKind::BruteForce,
        summary: 'SSH brute force',
        severity: AlertSeverity::Warning,
        subjectType: 'ip',
        // The same address written the long way.
        subjectValue: '2001:0db8:0000:0000:0000:0000:0000:0001',
        actor: $this->admin,
    );

    expect($case->customer_id)->toBe($customer->id);
});

/**
 * A report this platform cannot attribute is still a report somebody has to
 * answer. Dropping it would drop the answer with it.
 */
it('keeps a complaint it cannot attribute', function (): void {
    $case = app(AbuseCases::class)->open(
        organizationId: $this->provider->id,
        kind: AbuseKind::Phishing,
        summary: 'Phishing page reported by a bank',
        severity: AlertSeverity::Emergency,
        subjectType: 'ip',
        subjectValue: '203.0.113.99',
        actor: $this->admin,
    );

    expect($case->customer_id)->toBeNull()
        ->and($case->service_id)->toBeNull()
        ->and(AbuseCase::query()->count())->toBe(1)
        // And the timeline still starts with why it was opened.
        ->and($case->events()->count())->toBe(1);
});

it('suspends through the one path that suspends, and carries the reason', function (): void {
    $customer = abuseCustomer('The one who spammed');
    $service = $customer->services()->sole();

    $case = app(AbuseCases::class)->open(
        organizationId: $this->provider->id,
        kind: AbuseKind::Spam,
        summary: 'Outbound spam',
        severity: AlertSeverity::Critical,
        actor: $this->admin,
    );

    $record = app(AbuseCases::class)->act(
        $case,
        AbuseAction::SuspendService,
        'Third complaint in a week, no reply to either warning.',
        $service,
        $this->admin,
    );

    expect($record->state)->toBe(AbuseActionState::Done)
        ->and($record->performed_at)->not->toBeNull()
        // Suspended through `TransitionService`, not by writing a column.
        ->and($service->fresh()?->status)->toBe(ServiceStatus::Suspended);
});

/**
 * Three of the four actions have no seam here, and saying so is better than
 * an action that silently did nothing.
 */
it('records an action it cannot carry out as work for somebody', function (): void {
    $case = app(AbuseCases::class)->open(
        organizationId: $this->provider->id,
        kind: AbuseKind::Compromise,
        summary: 'Mailbox sending from three countries',
        severity: AlertSeverity::Critical,
        actor: $this->admin,
    );

    $record = app(AbuseCases::class)->act(
        $case,
        AbuseAction::ForcePasswordReset,
        'Credentials are almost certainly out.',
        null,
        $this->admin,
    );

    expect($record->state)->toBe(AbuseActionState::Manual)
        ->and($record->performed_at)->toBeNull();
});

it('refuses to suspend without saying which service', function (): void {
    $case = app(AbuseCases::class)->open(
        organizationId: $this->provider->id,
        kind: AbuseKind::Spam,
        summary: 'Outbound spam',
        severity: AlertSeverity::Critical,
        actor: $this->admin,
    );

    expect(fn (): AbuseActionRecord => app(AbuseCases::class)->act(
        $case,
        AbuseAction::SuspendService,
        'No service named.',
        null,
        $this->admin,
    ))->toThrow(AbuseRefused::class);
});

it('closes on a decision and refuses another note afterwards', function (): void {
    $case = app(AbuseCases::class)->open(
        organizationId: $this->provider->id,
        kind: AbuseKind::Blocklist,
        summary: 'Listed on a blocklist',
        severity: AlertSeverity::Warning,
        actor: $this->admin,
    );

    app(AbuseCases::class)->note(
        $case,
        'Delisted, and the customer had already fixed the relay.',
        AbuseState::NoAction,
        $this->admin,
    );

    $case = $case->fresh();

    expect($case?->state)->toBe(AbuseState::NoAction)
        ->and($case->closed_at)->not->toBeNull();

    expect(fn () => app(AbuseCases::class)->note($case, 'One more thing.', AbuseState::Open, $this->admin))
        ->toThrow(AbuseRefused::class);
});

it('forgets evidence that is past its deadline, and keeps the case', function (): void {
    $case = app(AbuseCases::class)->open(
        organizationId: $this->provider->id,
        kind: AbuseKind::Spam,
        summary: 'Outbound spam',
        severity: AlertSeverity::Warning,
        actor: $this->admin,
    );

    app(AbuseCases::class)->keep($case, EvidenceKind::MailMessageId, '<a@b.example>', $this->admin);

    AbuseEvidence::factory()
        ->expired()
        ->create([
            'organization_id' => $this->provider->id,
            'abuse_case_id' => $case->id,
        ]);

    expect(AbuseEvidence::query()->count())->toBe(2);

    app(TaskRegistry::class)->resolve(AutomationTask::AbuseRetention)->handle();

    // The expired one is gone; the one still in date is not — and neither the
    // case nor its timeline is touched. What the desk decided stays; what the
    // complaint contained does not.
    expect(AbuseEvidence::query()->count())->toBe(1)
        ->and(AbuseCase::query()->count())->toBe(1)
        ->and($case->events()->count())->toBe(1);
});

/** Run it twice and the second changes nothing (ADR 0031). */
it('is idempotent, like every sweep here', function (): void {
    $case = AbuseCase::factory()->create(['organization_id' => $this->provider->id]);

    AbuseEvidence::factory()
        ->expired()
        ->create(['organization_id' => $this->provider->id, 'abuse_case_id' => $case->id]);

    $first = app(TaskRegistry::class)->resolve(AutomationTask::AbuseRetention)->handle();
    $second = app(TaskRegistry::class)->resolve(AutomationTask::AbuseRetention)->handle();

    expect($first->changed)->toBe(1)
        ->and($second->changed)->toBe(0);
});

it('drives the screens', function (): void {
    $customer = abuseCustomer('Somebody');

    $this->actingAs($this->admin, 'staff')
        ->get('/admin/security/abuse')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Admin/Security/Abuse')
            ->has('cases.data', 0)
            ->where('can.manage', true));

    $this->actingAs($this->admin, 'staff')
        ->post('/admin/security/abuse', [
            'summary' => 'Outbound spam from a shared address',
            'kind' => AbuseKind::Spam->value,
            'severity' => AlertSeverity::Critical->value,
            'subject_type' => 'none',
            'source' => 'spamtrap.example.net',
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $case = AbuseCase::query()->sole();

    expect($case->reference)->toStartWith('ABU-');

    $this->actingAs($this->admin, 'staff')
        ->get('/admin/security/abuse/'.$case->id)
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Admin/Security/AbuseCase')
            ->has('abuseCase.events', 1)
            ->where('can.act', true));

    $this->actingAs($this->admin, 'staff')
        ->post('/admin/security/abuse/'.$case->id.'/notes', [
            'body' => 'Warned the customer, seven days to fix it.',
            'state' => AbuseState::WaitingCustomer->value,
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $this->actingAs($this->admin, 'staff')
        ->post('/admin/security/abuse/'.$case->id.'/evidence', [
            'kind' => EvidenceKind::Url->value,
            'reference' => 'https://example.invalid/phish',
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $this->actingAs($this->admin, 'staff')
        ->withSession([RequireRecentAuthentication::SESSION_KEY => now()->timestamp])
        ->post('/admin/security/abuse/'.$case->id.'/actions', [
            'action' => AbuseAction::ContactCustomer->value,
            'reason' => 'First warning.',
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect($case->fresh()?->state)->toBe(AbuseState::WaitingCustomer)
        ->and(AbuseEvidence::query()->count())->toBe(1)
        ->and(AbuseActionRecord::query()->count())->toBe(1)
        ->and($customer->exists)->toBeTrue();
});

it('asks for the password again before acting on a customer', function (): void {
    $case = AbuseCase::factory()->create(['organization_id' => $this->provider->id]);

    $this->actingAs($this->admin, 'staff')
        ->post('/admin/security/abuse/'.$case->id.'/actions', [
            'action' => AbuseAction::ContactCustomer->value,
            'reason' => 'Should not get through.',
        ])
        ->assertRedirect(route('admin.password.confirm'));

    expect(AbuseActionRecord::query()->count())->toBe(0);
});

/**
 * Recording is Support's — a complaint lands on whoever reads the mailbox.
 * Acting is not, for the reason issuing a credit is not.
 */
it('lets support record a complaint but not act on one', function (): void {
    $agent = StaffUser::factory()->create();
    $agent->assignRole(SystemRole::Support);

    $this->actingAs($agent->fresh(), 'staff')
        ->post('/admin/security/abuse', [
            'summary' => 'A customer reported a phishing page on our range',
            'kind' => AbuseKind::Phishing->value,
            'severity' => AlertSeverity::Critical->value,
            'subject_type' => 'none',
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $case = AbuseCase::query()->sole();

    $this->actingAs($agent->fresh(), 'staff')
        ->get('/admin/security/abuse/'.$case->id)
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('can.act', false));

    $this->actingAs($agent->fresh(), 'staff')
        ->withSession([RequireRecentAuthentication::SESSION_KEY => now()->timestamp])
        ->post('/admin/security/abuse/'.$case->id.'/actions', [
            'action' => AbuseAction::SuspendService->value,
            'reason' => 'Not mine to decide.',
        ])
        ->assertForbidden();

    expect(AbuseActionRecord::query()->count())->toBe(0);
});
