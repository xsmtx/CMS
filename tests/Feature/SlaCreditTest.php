<?php

declare(strict_types=1);

use App\Application\Access\SyncPermissions;
use App\Application\Automation\RecordedRun;
use App\Application\Automation\Runs\ProjectCoreResources;
use App\Application\Reliability\Incidents;
use App\Application\Reliability\IssueSlaCredit;
use App\Domain\Access\PermissionRegistry;
use App\Domain\Access\SystemRole;
use App\Domain\Automation\AutomationTask;
use App\Domain\Billing\InvoiceStatus;
use App\Domain\Organizations\OrganizationType;
use App\Domain\Provisioning\ServiceStatus;
use App\Domain\Reliability\AlertSeverity;
use App\Domain\Reliability\AlertState;
use App\Domain\Reliability\Exceptions\CreditRefused;
use App\Domain\Reliability\IncidentState;
use App\Domain\Shared\Money;
use App\Http\Middleware\RequireRecentAuthentication;
use App\Infrastructure\Billing\Models\CreditNote;
use App\Infrastructure\Billing\Models\Invoice;
use App\Infrastructure\Billing\Models\Transaction;
use App\Infrastructure\Crm\Models\Customer;
use App\Infrastructure\Identity\Models\StaffUser;
use App\Infrastructure\Organizations\Models\Organization;
use App\Infrastructure\Provisioning\Models\Server;
use App\Infrastructure\Provisioning\Models\Service;
use App\Infrastructure\Reliability\Models\Alert;
use App\Infrastructure\Reliability\Models\AlertRule;
use App\Infrastructure\Reliability\Models\Incident;
use App\Infrastructure\Reliability\Models\SlaCredit;
use App\Support\Organizations\OrganizationContext;
use Carbon\CarbonImmutable;
use Database\Seeders\ProviderOrganizationSeeder;
use Database\Seeders\SystemRoleSeeder;

/**
 * SLA credits and postmortems (§15).
 *
 * **A credit is a credit note and there is no second way to move money.**
 * ADR 0023 froze the issued invoice and ADR 0024 made the ledger the truth, so
 * this adds one row saying which incident a credit note was about and
 * delegates every rule about the money to `IssueCreditNote`.
 *
 * **Core does not work out how much.** An SLA is a contract this platform has
 * never read; a percentage invented here would be a commercial promise made on
 * a seller's behalf. What the platform answers instead is *who* — which is the
 * part only it can, because it is the only thing that knows the eleven
 * services on that machine belonged to nine customers.
 *
 * **A postmortem is the one editable thing on an incident**, and deliberately:
 * the timeline is evidence and a postmortem is a conclusion somebody revises.
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
 * One server, two customers with a service each, projected into the graph —
 * and an issued invoice apiece. Two of everything, because a figure that is
 * right for one customer is right by accident.
 *
 * @return array{server: Server, customers: list<Customer>, invoices: list<Invoice>}
 */
function creditEstate(Organization $provider): array
{
    $built = app(OrganizationContext::class)->withoutBoundary(function () use ($provider): array {
        $server = Server::factory()->create(['organization_id' => $provider->id]);

        $customers = [];
        $invoices = [];

        foreach (['EUR', 'EUR'] as $currency) {
            $customer = Customer::factory()->create();

            Service::factory()->create([
                'organization_id' => $customer->organization_id,
                'customer_id' => $customer->id,
                'server_id' => $server->id,
                'status' => ServiceStatus::Active->value,
                'currency_code' => $currency,
                'recurring_minor' => 5_000,
            ]);

            $customers[] = $customer;
            $invoices[] = Invoice::factory()
                ->forCustomer($customer)
                ->totalling(10_000, $currency)
                ->status(InvoiceStatus::Unpaid)
                ->create(['issued_on' => CarbonImmutable::now()->subDays(3)->toDateString()]);
        }

        return ['server' => $server, 'customers' => $customers, 'invoices' => $invoices];
    });

    app(RecordedRun::class)->handle(AutomationTask::Resources, app(ProjectCoreResources::class));

    return $built;
}

/** A resolved incident with an alert about that server attached to it. */
function creditIncident(Organization $provider, Server $server, StaffUser $actor): Incident
{
    $rule = AlertRule::factory()->create(['organization_id' => $provider->id]);

    $alert = Alert::query()->create([
        'organization_id' => $provider->id,
        'alert_rule_id' => $rule->id,
        'subject_key' => $server->id,
        'subject_label' => 'web-1',
        'state' => AlertState::Raised,
        'severity' => AlertSeverity::Emergency,
        'first_seen_at' => CarbonImmutable::now()->subHours(4),
        'last_seen_at' => CarbonImmutable::now(),
        'dedupe_token' => '',
    ]);

    $incidents = app(Incidents::class);

    $incident = $incidents->open(
        organizationId: $provider->id,
        title: 'Four hours of downtime on the shared cluster',
        body: 'The whole machine was unreachable.',
        severity: AlertSeverity::Emergency,
        actor: $actor,
    );

    $incidents->attach($incident, $alert, $actor);
    $incidents->resolve($incident, 'Back up.', $actor);

    return $incident->fresh() ?? $incident;
}

it('answers who was affected, which is the part only this platform knows', function (): void {
    $estate = creditEstate($this->provider);
    $incident = creditIncident($this->provider, $estate['server'], $this->admin);

    $this->actingAs($this->admin, 'staff')
        ->get('/admin/reliability/incidents/'.$incident->id)
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('affected', 2)
            ->where('affected.0.services', 1)
            // Their issued invoice, offered to credit.
            ->has('affected.0.invoices', 1)
            ->where('can.credit', true));
});

it('raises a credit note and links it to the incident', function (): void {
    $estate = creditEstate($this->provider);
    $incident = creditIncident($this->provider, $estate['server'], $this->admin);
    $invoice = $estate['invoices'][0];

    $this->actingAs($this->admin, 'staff')
        ->withSession([RequireRecentAuthentication::SESSION_KEY => now()->timestamp])
        ->post('/admin/reliability/incidents/'.$incident->id.'/credits', [
            'invoice' => $invoice->id,
            'amount_minor' => 2_500,
            'reason' => 'Four hours of downtime on 26 September.',
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $credit = SlaCredit::query()->sole();
    $note = CreditNote::query()->sole();

    expect($credit->incident_id)->toBe($incident->id)
        ->and($credit->invoice_id)->toBe($invoice->id)
        ->and($credit->credit_note_id)->toBe($note->id)
        ->and($credit->amount_minor)->toBe(2_500)
        ->and($credit->currency_code)->toBe('EUR')
        // The money moved once, through the ledger, and the sentence the
        // customer reads is the operator's rather than the reference.
        ->and($note->reason)->toBe('Four hours of downtime on 26 September.')
        ->and(Transaction::query()->count())->toBe(1);
});

/**
 * Two people looking at the same outage on the same morning is the ordinary
 * case, and the second must be refused by the database rather than by a screen
 * rendered before the first one pressed the button.
 */
it('will not credit the same invoice twice for one incident', function (): void {
    $estate = creditEstate($this->provider);
    $incident = creditIncident($this->provider, $estate['server'], $this->admin);
    $invoice = $estate['invoices'][0];

    app(IssueSlaCredit::class)->handle(
        $incident,
        $invoice,
        Money::ofMinor(1_000, 'EUR'),
        'The first one.',
        $this->admin,
    );

    $this->actingAs($this->admin, 'staff')
        ->withSession([RequireRecentAuthentication::SESSION_KEY => now()->timestamp])
        ->post('/admin/reliability/incidents/'.$incident->id.'/credits', [
            'invoice' => $invoice->id,
            'amount_minor' => 1_000,
            'reason' => 'The second one.',
        ])
        ->assertRedirect()
        ->assertSessionHasErrors('amount_minor');

    expect(SlaCredit::query()->count())->toBe(1)
        ->and(CreditNote::query()->count())->toBe(1);
});

/** A duration nobody knows yet is a figure nobody can agree. */
it('refuses to credit an incident that is still going', function (): void {
    $estate = creditEstate($this->provider);

    $incident = app(Incidents::class)->open(
        organizationId: $this->provider->id,
        title: 'Still going',
        body: 'Looking at it.',
        severity: AlertSeverity::Critical,
        actor: $this->admin,
    );

    expect(fn (): SlaCredit => app(IssueSlaCredit::class)->handle(
        $incident,
        $estate['invoices'][0],
        Money::ofMinor(1_000, 'EUR'),
        'Too soon.',
        $this->admin,
    ))->toThrow(CreditRefused::class);

    expect(SlaCredit::query()->count())->toBe(0);
});

/** The rules about the money belong to the one class that owns them. */
it('refuses more than the invoice was for', function (): void {
    $estate = creditEstate($this->provider);
    $incident = creditIncident($this->provider, $estate['server'], $this->admin);

    $this->actingAs($this->admin, 'staff')
        ->withSession([RequireRecentAuthentication::SESSION_KEY => now()->timestamp])
        ->post('/admin/reliability/incidents/'.$incident->id.'/credits', [
            'invoice' => $estate['invoices'][0]->id,
            'amount_minor' => 99_999,
            'reason' => 'More than the whole invoice.',
        ])
        ->assertRedirect()
        ->assertSessionHasErrors('amount_minor');

    expect(SlaCredit::query()->count())->toBe(0)
        ->and(CreditNote::query()->count())->toBe(0);
});

it('asks for the password again before it moves money', function (): void {
    $estate = creditEstate($this->provider);
    $incident = creditIncident($this->provider, $estate['server'], $this->admin);

    // No recent-authentication marker in the session.
    $this->actingAs($this->admin, 'staff')
        ->post('/admin/reliability/incidents/'.$incident->id.'/credits', [
            'invoice' => $estate['invoices'][0]->id,
            'amount_minor' => 1_000,
            'reason' => 'Should not get through.',
        ])
        ->assertRedirect(route('admin.password.confirm'));

    expect(SlaCredit::query()->count())->toBe(0);
});

/**
 * The password is asked for **before** the form, not on the way out of it.
 * `auth.recent` redirects with a GET, so a challenge on submit throws away
 * the amount and the sentence somebody just typed — and every other
 * re-challenged action in this product is a bare button press.
 */
it('sends somebody to confirm their password before the form opens', function (): void {
    $estate = creditEstate($this->provider);
    $incident = creditIncident($this->provider, $estate['server'], $this->admin);

    // Stale session: the screen says so, and the button goes to the challenge.
    $this->actingAs($this->admin, 'staff')
        ->get('/admin/reliability/incidents/'.$incident->id)
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('can.confirmed', false));

    $this->actingAs($this->admin, 'staff')
        ->get('/admin/reliability/incidents/'.$incident->id.'/credits/confirm')
        ->assertRedirect(route('admin.password.confirm'));

    // Fresh: the screen says so, and the same route sends them straight back.
    $this->actingAs($this->admin, 'staff')
        ->withSession([RequireRecentAuthentication::SESSION_KEY => now()->timestamp])
        ->get('/admin/reliability/incidents/'.$incident->id)
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('can.confirmed', true));

    $this->actingAs($this->admin, 'staff')
        ->withSession([RequireRecentAuthentication::SESSION_KEY => now()->timestamp])
        ->get('/admin/reliability/incidents/'.$incident->id.'/credits/confirm')
        ->assertRedirect(route('admin.reliability.incidents.show', $incident));
});

/**
 * Opening an incident is Support's; deciding what an outage is worth is not.
 * The list of who was affected is not sent either — it is a list of who had a
 * bad day, and somebody who cannot act on it has no reason to hold it.
 */
it('does not offer credits to somebody who may not issue them', function (): void {
    $estate = creditEstate($this->provider);
    $incident = creditIncident($this->provider, $estate['server'], $this->admin);

    $agent = StaffUser::factory()->create();
    $agent->assignRole(SystemRole::Support);

    $this->actingAs($agent->fresh(), 'staff')
        ->get('/admin/reliability/incidents/'.$incident->id)
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('can.credit', false)
            ->has('affected', 0));

    $this->actingAs($agent->fresh(), 'staff')
        ->withSession([RequireRecentAuthentication::SESSION_KEY => now()->timestamp])
        ->post('/admin/reliability/incidents/'.$incident->id.'/credits', [
            'invoice' => $estate['invoices'][0]->id,
            'amount_minor' => 1_000,
            'reason' => 'Not mine to give.',
        ])
        ->assertForbidden();

    expect(SlaCredit::query()->count())->toBe(0);
});

it('writes, revises and clears a postmortem', function (): void {
    $estate = creditEstate($this->provider);
    $incident = creditIncident($this->provider, $estate['server'], $this->admin);

    $this->actingAs($this->admin, 'staff')
        ->put('/admin/reliability/incidents/'.$incident->id.'/postmortem', [
            'postmortem' => 'The filer ran out of inodes.',
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $incident = $incident->fresh();

    expect($incident?->postmortem)->toBe('The filer ran out of inodes.')
        ->and($incident->postmortem_at)->not->toBeNull();

    // Revised, because a postmortem is a conclusion rather than evidence.
    $this->actingAs($this->admin, 'staff')
        ->put('/admin/reliability/incidents/'.$incident->id.'/postmortem', [
            'postmortem' => 'The filer ran out of inodes, and the alert for it was disabled.',
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect($incident->fresh()?->postmortem)->toContain('the alert for it was disabled');

    // Cleared, and the date goes with it: a date saying one was written,
    // beside no postmortem, is a record contradicting itself.
    $this->actingAs($this->admin, 'staff')
        ->put('/admin/reliability/incidents/'.$incident->id.'/postmortem', ['postmortem' => ''])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $incident = $incident->fresh();

    expect($incident?->postmortem)->toBeNull()
        ->and($incident->postmortem_at)->toBeNull();
});

it('refuses a postmortem while the incident is still going', function (): void {
    $incident = Incident::factory()
        ->inState(IncidentState::Investigating)
        ->create(['organization_id' => $this->provider->id]);

    $this->actingAs($this->admin, 'staff')
        ->put('/admin/reliability/incidents/'.$incident->id.'/postmortem', [
            'postmortem' => 'Too early to say.',
        ])
        ->assertRedirect()
        ->assertSessionHasErrors('postmortem');

    expect($incident->fresh()?->postmortem)->toBeNull();
});
