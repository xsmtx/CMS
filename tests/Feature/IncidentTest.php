<?php

declare(strict_types=1);

use App\Application\Access\SyncPermissions;
use App\Application\Automation\RecordedRun;
use App\Application\Automation\Runs\ProjectCoreResources;
use App\Application\Reliability\Incidents;
use App\Domain\Access\PermissionRegistry;
use App\Domain\Access\SystemRole;
use App\Domain\Automation\AutomationTask;
use App\Domain\Organizations\OrganizationType;
use App\Domain\Provisioning\ServiceStatus;
use App\Domain\Reliability\AlertSeverity;
use App\Domain\Reliability\AlertState;
use App\Domain\Reliability\Exceptions\IncidentRefused;
use App\Domain\Reliability\IncidentState;
use App\Infrastructure\Crm\Models\Customer;
use App\Infrastructure\Identity\Models\StaffUser;
use App\Infrastructure\Organizations\Models\Organization;
use App\Infrastructure\Provisioning\Models\Server;
use App\Infrastructure\Provisioning\Models\Service;
use App\Infrastructure\Reliability\Models\Alert;
use App\Infrastructure\Reliability\Models\AlertRule;
use App\Infrastructure\Reliability\Models\Incident;
use App\Infrastructure\Reliability\Models\IncidentImpact;
use App\Infrastructure\Reliability\Models\IncidentUpdate;
use App\Infrastructure\Resources\Models\ResourceNode;
use App\Support\Organizations\OrganizationContext;
use Carbon\CarbonImmutable;
use Database\Seeders\ProviderOrganizationSeeder;
use Database\Seeders\SystemRoleSeeder;

/**
 * Incidents (`phase-d-plan.md` §15).
 *
 * Five things about them are the design rather than the implementation:
 *
 * **An incident always has at least one update.** Opening writes it, so the
 * timeline starts with why it was opened rather than with a gap somebody has
 * to explain afterwards — and a postmortem is written from the timeline.
 *
 * **The state and the sentence are one act.** There is no way to move an
 * incident to `identified` without saying what was identified, because that
 * move is what makes a status page useless.
 *
 * **Resolving freezes the impact.** The graph moves; an impact recomputed in
 * March is not the impact anybody acted on in January, and it is the figure
 * somebody quotes in a credit conversation.
 *
 * **Opening one is Support's.** The person answering "is it just me?" finds
 * out first, and a platform where they had to go and find somebody senior to
 * press the button is a platform where the first ten minutes are spent
 * looking for that person.
 *
 * **A private incident cannot have a public update.** Publishing a sentence
 * about something nobody has been told exists is the worst possible order to
 * say things in.
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
 * One server carrying two services for two customers, projected into the
 * graph — the shape an impact figure is computed from.
 *
 * Two of everything on purpose: strict mode only reports a lazy load when the
 * query returned more than one row, and a figure that is right for one service
 * is right by accident.
 */
function incidentEstate(Organization $provider): Server
{
    $server = app(OrganizationContext::class)->withoutBoundary(function () use ($provider): Server {
        $server = Server::factory()->create(['organization_id' => $provider->id]);

        foreach (['EUR', 'TRY'] as $currency) {
            $customer = Customer::factory()->create();

            Service::factory()->create([
                'organization_id' => $customer->organization_id,
                'customer_id' => $customer->id,
                'server_id' => $server->id,
                'status' => ServiceStatus::Active->value,
                'currency_code' => $currency,
                'recurring_minor' => 2_500,
            ]);
        }

        return $server;
    });

    app(RecordedRun::class)->handle(AutomationTask::Resources, app(ProjectCoreResources::class));

    return $server;
}

/** An open alert about that server, the way `EvaluateAlertRule` leaves one. */
function incidentAlert(Server $server, string $organizationId): Alert
{
    $rule = AlertRule::factory()->create(['organization_id' => $organizationId]);

    return Alert::query()->create([
        'organization_id' => $organizationId,
        'alert_rule_id' => $rule->id,
        // The node key of a projected server is the server's own id, which is
        // what `freezeImpact()` looks the node up by.
        'subject_key' => $server->id,
        'subject_label' => 'web-1',
        'state' => AlertState::Raised,
        'severity' => AlertSeverity::Critical,
        'observed' => '0.94',
        'first_seen_at' => CarbonImmutable::now()->subMinutes(20),
        'last_seen_at' => CarbonImmutable::now(),
        'dedupe_token' => '',
    ]);
}

it('opens an incident with its first update already written', function (): void {
    $this->actingAs($this->admin, 'staff')
        ->post('/admin/reliability/incidents', [
            'title' => 'Database failover did not complete',
            'body' => 'Writes are failing on the primary. Looking at the replica now.',
            'severity' => AlertSeverity::Emergency->value,
            'is_public' => true,
        ])
        // `assertSessionHasNoErrors` alone passes against a 403 and a 404.
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $incident = Incident::query()->sole();

    expect($incident->reference)->toStartWith('INC-')
        ->and($incident->state)->toBe(IncidentState::Investigating)
        ->and($incident->severity)->toBe(AlertSeverity::Emergency)
        ->and($incident->is_public)->toBeTrue()
        ->and($incident->opened_by)->toBe($this->admin->id)
        // The timeline begins with why it was opened, not with a gap.
        ->and($incident->updates()->count())->toBe(1)
        ->and($incident->updates()->sole()->state)->toBe(IncidentState::Investigating);
});

/**
 * `started_at` is when the customer's world broke, which is what an SLA is
 * measured from and is usually earlier than anybody noticed. Leaving it empty
 * means the platform has no better answer than the moment it was opened.
 */
it('separates when it started from when anybody noticed', function (): void {
    CarbonImmutable::setTestNow('2026-09-26 14:00:00');

    $this->actingAs($this->admin, 'staff')
        ->post('/admin/reliability/incidents', [
            'title' => 'Mail queue stopped',
            'body' => 'Nothing has left the queue since lunch.',
            'severity' => AlertSeverity::Critical->value,
            'started_at' => '2026-09-26 12:30:00',
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $incident = Incident::query()->sole();

    expect($incident->started_at->toDateTimeString())->toBe('2026-09-26 12:30:00')
        ->and($incident->detected_at?->toDateTimeString())->toBe('2026-09-26 14:00:00')
        // Still going, so there is no duration to quote.
        ->and($incident->durationSeconds())->toBeNull();
});

it('moves the state and writes the sentence in one act', function (): void {
    $incident = Incident::factory()->create(['organization_id' => $this->provider->id]);

    $this->actingAs($this->admin, 'staff')
        ->post('/admin/reliability/incidents/'.$incident->id.'/updates', [
            'body' => 'A full disk on the replica. Clearing it now.',
            'state' => IncidentState::Identified->value,
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $incident = $incident->fresh();

    expect($incident?->state)->toBe(IncidentState::Identified)
        // The update carries the state it was written at, so the timeline
        // still reads correctly after the next three moves.
        ->and(IncidentUpdate::query()->sole()->state)->toBe(IncidentState::Identified);
});

/**
 * Resolving has a figure to freeze and a timestamp to write, so it is its own
 * path. A second way to end an incident would be the one that forgot.
 */
it('refuses to resolve through the ordinary update path', function (): void {
    $incident = Incident::factory()->create(['organization_id' => $this->provider->id]);

    expect(fn (): IncidentUpdate => app(Incidents::class)->note(
        $incident,
        'It is fine now.',
        IncidentState::Resolved,
    ))->toThrow(IncidentRefused::class);
});

it('freezes what was underneath when it is resolved', function (): void {
    $server = incidentEstate($this->provider);
    $alert = incidentAlert($server, $this->provider->id);

    $incident = Incident::factory()->create(['organization_id' => $this->provider->id]);

    $this->actingAs($this->admin, 'staff')
        ->post('/admin/reliability/incidents/'.$incident->id.'/alerts', ['alert' => $alert->id])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $this->actingAs($this->admin, 'staff')
        ->post('/admin/reliability/incidents/'.$incident->id.'/updates', [
            'body' => 'The disk is clear and writes have caught up.',
            'state' => IncidentState::Resolved->value,
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $incident = $incident->fresh();

    expect($incident?->state)->toBe(IncidentState::Resolved)
        ->and($incident->resolved_at)->not->toBeNull()
        ->and($incident->durationSeconds())->toBeGreaterThan(0);

    $impact = IncidentImpact::query()->sole();

    // Money per currency, never a total: there is no rate anywhere in this
    // product, so a figure added across currencies would mean nothing.
    $byCurrency = collect($impact->recurring)->pluck('minor', 'currency')->all();

    expect($impact->services)->toBe(2)
        ->and($impact->customers)->toBe(2)
        ->and($byCurrency)->toBe(['EUR' => 2_500, 'TRY' => 2_500]);

    // And it does not move afterwards, which is the whole point of freezing it.
    Service::query()->update(['recurring_minor' => 99_999]);

    expect(IncidentImpact::query()->sole()->recurring)->toBe($impact->recurring);
});

/** An incident with no alerts freezes zeroes rather than nothing. */
it('freezes zeroes when nothing was attached', function (): void {
    $incident = Incident::factory()->create(['organization_id' => $this->provider->id]);

    app(Incidents::class)->resolve($incident, 'It was a customer typo.', $this->admin);

    $impact = IncidentImpact::query()->sole();

    expect($impact->services)->toBe(0)
        ->and($impact->customers)->toBe(0)
        ->and($impact->recurring)->toBe([]);
});

it('refuses another update once it is resolved', function (): void {
    $incident = Incident::factory()
        ->inState(IncidentState::Resolved)
        ->create(['organization_id' => $this->provider->id]);

    $this->actingAs($this->admin, 'staff')
        ->post('/admin/reliability/incidents/'.$incident->id.'/updates', [
            'body' => 'One more thing.',
            'state' => IncidentState::Monitoring->value,
        ])
        ->assertRedirect()
        ->assertSessionHasErrors('body');

    expect($incident->fresh()?->state)->toBe(IncidentState::Resolved);
});

it('attaches an alert and detaches it again', function (): void {
    $server = incidentEstate($this->provider);
    $alert = incidentAlert($server, $this->provider->id);

    $incident = Incident::factory()->create(['organization_id' => $this->provider->id]);

    $this->actingAs($this->admin, 'staff')
        ->post('/admin/reliability/incidents/'.$incident->id.'/alerts', ['alert' => $alert->id])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect($alert->fresh()?->incident_id)->toBe($incident->id);

    $this->actingAs($this->admin, 'staff')
        ->delete('/admin/reliability/alerts/'.$alert->id.'/incident')
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    // The alert survives the incident it was attached to, and vice versa: an
    // incident outlives the rule that raised its alerts.
    expect($alert->fresh()?->incident_id)->toBeNull()
        ->and(Incident::query()->count())->toBe(1);
});

/**
 * The impact was computed from exactly these alerts and then frozen. Letting
 * the evidence move afterwards leaves a stored figure nobody can reconcile
 * with the rows beside it on the screen.
 */
it('will not change the evidence after the figure is frozen', function (): void {
    $server = incidentEstate($this->provider);
    $attached = incidentAlert($server, $this->provider->id);

    $incident = Incident::factory()->create(['organization_id' => $this->provider->id]);

    app(Incidents::class)->attach($incident, $attached, $this->admin);
    app(Incidents::class)->resolve($incident, 'Done.', $this->admin);

    $this->actingAs($this->admin, 'staff')
        ->delete('/admin/reliability/alerts/'.$attached->id.'/incident')
        ->assertRedirect()
        ->assertSessionHasErrors('alert');

    expect($attached->fresh()?->incident_id)->toBe($incident->id);

    $loose = Alert::query()->create([
        'organization_id' => $this->provider->id,
        'alert_rule_id' => $attached->alert_rule_id,
        'subject_key' => $server->id,
        'subject_label' => 'web-1 again',
        'state' => AlertState::Raised,
        'severity' => AlertSeverity::Warning,
        'first_seen_at' => CarbonImmutable::now(),
        'last_seen_at' => CarbonImmutable::now(),
        'dedupe_token' => 'second',
    ]);

    $this->actingAs($this->admin, 'staff')
        ->post('/admin/reliability/incidents/'.$incident->id.'/alerts', ['alert' => $loose->id])
        ->assertRedirect()
        ->assertSessionHasErrors('alert');

    expect($loose->fresh()?->incident_id)->toBeNull();
});

/**
 * Publishing a sentence about something nobody has been told exists is the
 * worst possible order to say things in.
 */
it('will not publish an update on an incident that is not public', function (): void {
    $incident = Incident::factory()->create([
        'organization_id' => $this->provider->id,
        'is_public' => false,
    ]);

    app(Incidents::class)->note($incident, 'Still looking.', IncidentState::Identified, null, true);

    expect(IncidentUpdate::query()->latest('created_at')->firstOrFail()->is_public)->toBeFalse();
});

it('renders the list and one incident', function (): void {
    $server = incidentEstate($this->provider);
    $alert = incidentAlert($server, $this->provider->id);

    $incident = Incident::factory()->create(['organization_id' => $this->provider->id]);
    IncidentUpdate::factory()->create([
        'organization_id' => $this->provider->id,
        'incident_id' => $incident->id,
    ]);

    // A second one, so nothing passes because there was one of everything.
    Incident::factory()->create(['organization_id' => $this->provider->id]);

    $this->actingAs($this->admin, 'staff')
        ->get('/admin/reliability/incidents')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Admin/Reliability/Incidents')
            ->has('incidents.data', 2)
            // A status crossing to the browser is the value, the word and the
            // tone the server decided — never one of them.
            ->where('incidents.data.0.state', 'investigating')
            ->where('incidents.data.0.stateTone', 'critical')
            ->where('incidents.data.0.stateLabel', 'Investigating')
            ->where('can.manage', true)
            // A screen that paginates server-side must send something to link
            // page two with.
            ->has('incidents.links'));

    $this->actingAs($this->admin, 'staff')
        ->get('/admin/reliability/incidents/'.$incident->id)
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Admin/Reliability/Incident')
            ->has('incident.updates', 1)
            ->has('states', 4)
            // The open alert nobody has attached, so the one action an
            // operator wants in the first minute is one press away.
            ->has('unattached', 1)
            ->where('unattached.0.id', $alert->id)
            ->where('incident.impact', null));
});

/** A resolved incident stays off the list unless it is asked for. */
it('shows what is open by default and everything on request', function (): void {
    Incident::factory()->create(['organization_id' => $this->provider->id]);
    Incident::factory()
        ->inState(IncidentState::Resolved)
        ->create(['organization_id' => $this->provider->id]);

    $this->actingAs($this->admin, 'staff')
        ->get('/admin/reliability/incidents')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('incidents.data', 1));

    $this->actingAs($this->admin, 'staff')
        ->get('/admin/reliability/incidents?all=1')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('incidents.data', 2));
});

/**
 * The permission decision, asserted rather than described: the person
 * answering "is it just me?" can open one.
 */
it('lets support open an incident', function (): void {
    $agent = StaffUser::factory()->create();
    $agent->assignRole(SystemRole::Support);

    $this->actingAs($agent->fresh(), 'staff')
        ->post('/admin/reliability/incidents', [
            'title' => 'Three customers cannot reach webmail',
            'body' => 'All on the same server. Escalating.',
            'severity' => AlertSeverity::Critical->value,
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect(Incident::query()->count())->toBe(1);
});

it('refuses somebody holding neither permission', function (): void {
    // A staff account with no role at all, which is what a new hire is for the
    // five minutes before somebody assigns them one.
    $nobody = StaffUser::factory()->create();

    $this->actingAs($nobody->fresh(), 'staff')
        ->get('/admin/reliability/incidents')
        ->assertForbidden();
});

/**
 * A node the alert points at is looked up by key, and a key nothing matches
 * must not be a resolve that throws: the incident still ends.
 */
it('resolves an incident whose alert points at nothing in the graph', function (): void {
    $rule = AlertRule::factory()->create(['organization_id' => $this->provider->id]);

    $alert = Alert::query()->create([
        'organization_id' => $this->provider->id,
        'alert_rule_id' => $rule->id,
        'subject_key' => 'a-machine-nobody-projected',
        'subject_label' => 'ghost',
        'state' => AlertState::Raised,
        'severity' => AlertSeverity::Warning,
        'first_seen_at' => CarbonImmutable::now(),
        'last_seen_at' => CarbonImmutable::now(),
        'dedupe_token' => '',
    ]);

    $incident = Incident::factory()->create(['organization_id' => $this->provider->id]);

    app(Incidents::class)->attach($incident, $alert, $this->admin);
    app(Incidents::class)->resolve($incident, 'Nothing was wrong.', $this->admin);

    expect(IncidentImpact::query()->sole()->services)->toBe(0)
        ->and(ResourceNode::query()->where('node_key', 'a-machine-nobody-projected')->count())
        ->toBe(0)
        ->and($incident->fresh()?->state)->toBe(IncidentState::Resolved);
});

/** An alert from somebody else's subtree is not evidence for this incident. */
it('refuses an alert from another organization', function (): void {
    $other = Organization::factory()->create([
        'parent_id' => $this->provider->id,
        'type' => OrganizationType::Customer->value,
    ]);

    $incident = Incident::factory()->create(['organization_id' => $this->provider->id]);

    $alert = app(OrganizationContext::class)->withoutBoundary(function () use ($other): Alert {
        $rule = AlertRule::factory()->create(['organization_id' => $other->id]);

        return Alert::query()->create([
            'organization_id' => $other->id,
            'alert_rule_id' => $rule->id,
            'subject_key' => 'theirs',
            'subject_label' => 'theirs',
            'state' => AlertState::Raised,
            'severity' => AlertSeverity::Warning,
            'first_seen_at' => CarbonImmutable::now(),
            'last_seen_at' => CarbonImmutable::now(),
            'dedupe_token' => '',
        ]);
    });

    expect(fn () => app(Incidents::class)->attach($incident, $alert))
        ->toThrow(IncidentRefused::class);
});

/** Every state and every severity is named, in both languages. */
it('names every incident state in both languages', function (): void {
    foreach (['en', 'tr'] as $locale) {
        app()->setLocale($locale);

        foreach (IncidentState::cases() as $state) {
            expect(__($state->labelKey()))->not->toBe($state->labelKey());
        }
    }

    app()->setLocale('en');
});
