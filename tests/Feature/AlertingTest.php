<?php

declare(strict_types=1);

use App\Application\Access\SyncPermissions;
use App\Application\Automation\TaskRegistry;
use App\Application\Infrastructure\ResourceGraph;
use App\Application\Reliability\EvaluateAlertRule;
use App\Domain\Access\PermissionRegistry;
use App\Domain\Access\SystemRole;
use App\Domain\Automation\AutomationTask;
use App\Domain\Infrastructure\MetricKind;
use App\Domain\Infrastructure\MetricUnit;
use App\Domain\Infrastructure\ResourceKind;
use App\Domain\Organizations\OrganizationType;
use App\Domain\Reliability\AlertComparison;
use App\Domain\Reliability\AlertSeverity;
use App\Domain\Reliability\AlertState;
use App\Domain\Reliability\AlertSubject;
use App\Infrastructure\Identity\Models\StaffUser;
use App\Infrastructure\Organizations\Models\Organization;
use App\Infrastructure\Reliability\Models\Alert;
use App\Infrastructure\Reliability\Models\AlertRule;
use App\Infrastructure\Resources\Models\ResourceMetric;
use App\Support\Organizations\OrganizationContext;
use Carbon\CarbonImmutable;
use Database\Seeders\ProviderOrganizationSeeder;
use Database\Seeders\SystemRoleSeeder;

/**
 * Alerting (`phase-d-plan.md` §15).
 *
 * Four things about it are the design rather than the implementation, and
 * each has a test here:
 *
 * **One open alert per rule and subject.** A disk that crosses 90% every
 * minute for six hours is one alert seen 360 times, not 360 rows — the guard
 * against repeating is state (ADR 0031), and the unique index needs
 * `dedupe_token` because MariaDB treats nulls in a unique index as distinct.
 *
 * **Clearing is the half people forget.** A rule that only ever raised would
 * fill a screen with things that stopped being true days ago, and an operator
 * who has learned the list is stale is an operator who does not read it.
 *
 * **`for_minutes` is honoured without a series.** The alert's own
 * `first_seen_at` is the history: the row exists from the first bad
 * observation and only becomes visible once it has been bad long enough.
 *
 * **Stale readings are skipped, not alerted on.** A machine that stopped
 * reporting is a monitoring problem, not a disk that is 94% full, and raising
 * the last known value for ever would be this platform asserting something it
 * no longer knows.
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

    $this->node = app(ResourceGraph::class)->upsertNode(
        organizationId: $this->provider->id,
        kind: ResourceKind::Server,
        nodeKey: 'web-1.dc2',
        label: 'web-1',
    );
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

/** A current reading, the way `RecordSamples` leaves one. */
function reading(float $value, ?int $staleAfter = 300): ResourceMetric
{
    return ResourceMetric::query()->updateOrCreate(
        [
            'resource_node_id' => test()->node->id,
            'metric' => MetricKind::CpuUtilisation->value,
        ],
        [
            'organization_id' => test()->provider->id,
            'unit' => MetricUnit::Ratio->value,
            'value' => $value,
            'source' => 'test',
            'sampled_at' => CarbonImmutable::now(),
            'stale_after_seconds' => $staleAfter,
        ],
    );
}

function cpuRule(array $overrides = []): AlertRule
{
    return AlertRule::factory()->create($overrides + [
        'organization_id' => test()->provider->id,
        'subject' => AlertSubject::Metric,
        'target' => MetricKind::CpuUtilisation->value,
        'comparison' => AlertComparison::Above,
        'threshold_ppm' => 900_000,
    ]);
}

it('raises when the reading crosses the line, and not before', function (): void {
    $rule = cpuRule();

    reading(0.84);
    app(EvaluateAlertRule::class)->handle($rule);

    expect(Alert::query()->count())->toBe(0);

    reading(0.94);
    $outcome = app(EvaluateAlertRule::class)->handle($rule);

    expect($outcome['raised'])->toBe(1);

    $alert = Alert::query()->sole();

    expect($alert->subject_key)->toBe('web-1.dc2')
        // The label an operator reads, which is not the key it de-duplicates
        // on: a list keyed on the label would split in two the day somebody
        // renamed a server.
        ->and($alert->subject_label)->toBe('web-1')
        ->and($alert->observed)->toBe('94.0%')
        ->and($alert->state)->toBe(AlertState::Raised);
});

it('counts a repeat rather than writing it again', function (): void {
    $rule = cpuRule();
    reading(0.94);

    app(EvaluateAlertRule::class)->handle($rule);
    app(EvaluateAlertRule::class)->handle($rule);
    $third = app(EvaluateAlertRule::class)->handle($rule);

    expect(Alert::query()->count())->toBe(1)
        ->and($third['raised'])->toBe(0)
        ->and($third['kept'])->toBe(1)
        ->and(Alert::query()->sole()->occurrences)->toBe(3);
});

/**
 * The half people forget. A row that stayed open after the disk was made
 * bigger is a list an operator stops reading.
 */
it('clears when it stops being true, and keeps the row', function (): void {
    $rule = cpuRule();

    reading(0.94);
    app(EvaluateAlertRule::class)->handle($rule);

    reading(0.40);
    $outcome = app(EvaluateAlertRule::class)->handle($rule);

    $alert = Alert::query()->sole();

    expect($outcome['cleared'])->toBe(1)
        ->and($alert->state)->toBe(AlertState::Cleared)
        ->and($alert->cleared_at)->not->toBeNull()
        // The token that lets the same subject alert again. A null here would
        // not collide with another null, which is the whole reason it exists.
        ->and($alert->dedupe_token)->toBe($alert->id);
});

/** And the same subject may then alert again, which is what the token buys. */
it('lets a cleared subject alert again', function (): void {
    $rule = cpuRule();

    reading(0.94);
    app(EvaluateAlertRule::class)->handle($rule);
    reading(0.40);
    app(EvaluateAlertRule::class)->handle($rule);
    reading(0.95);
    app(EvaluateAlertRule::class)->handle($rule);

    expect(Alert::query()->count())->toBe(2)
        ->and(Alert::query()->open()->count())->toBe(1);
});

/**
 * A spike that lasts nine seconds is not an alert, and the platform decides
 * that without storing a series: the alert's own `first_seen_at` is the
 * history.
 */
it('holds an alert until it has been true for long enough', function (): void {
    $rule = cpuRule(['for_minutes' => 10]);

    reading(0.94);
    app(EvaluateAlertRule::class)->handle($rule);

    // The row exists — it has to, or there would be nothing to measure the
    // duration from — but nobody is told.
    expect(Alert::query()->sole()->state)->toBe(AlertState::Suppressed);

    CarbonImmutable::setTestNow(CarbonImmutable::now()->addMinutes(11));

    reading(0.94);
    app(EvaluateAlertRule::class)->handle($rule);

    expect(Alert::query()->sole()->state)->toBe(AlertState::Raised);
});

/**
 * A machine that stopped reporting is a monitoring problem, not a disk that
 * is 94% full — and raising the last known value for ever would be this
 * platform asserting something it no longer knows.
 */
it('does not alert on a reading nobody is taking any more', function (): void {
    $rule = cpuRule();

    reading(0.94, staleAfter: 60);

    CarbonImmutable::setTestNow(CarbonImmutable::now()->addMinutes(5));

    expect(app(EvaluateAlertRule::class)->handle($rule)['raised'])->toBe(0)
        ->and(Alert::query()->count())->toBe(0);
});

/**
 * Every subject but `metric` reads a table this installation has had since
 * handoff #1 — which is the whole point of alerting living in core. An
 * operator who installs nothing still gets told when a health check fails.
 */
it('alerts on a health check with no adapter configured at all', function (): void {
    $rule = AlertRule::factory()->create([
        'organization_id' => $this->provider->id,
        'name' => 'The scheduler has stopped',
        'subject' => AlertSubject::HealthCheck,
        'target' => 'scheduler',
        'comparison' => null,
        'threshold_ppm' => null,
        'severity' => AlertSeverity::Critical,
    ]);

    // Nothing has ever pinged the scheduler heartbeat, which is exactly what
    // a scheduler that is not running looks like.
    $outcome = app(EvaluateAlertRule::class)->handle($rule);

    expect($outcome['raised'])->toBe(1)
        ->and(Alert::query()->sole()->severity)->toBe(AlertSeverity::Critical);
});

/**
 * A numeric rule somebody left blank must match nothing. A rule that alerted
 * on everything because of an empty field is the one that teaches people to
 * ignore the list.
 */
it('matches nothing when a numeric rule has no threshold', function (): void {
    $rule = cpuRule(['threshold_ppm' => null, 'comparison' => null]);

    reading(0.99);

    expect(app(EvaluateAlertRule::class)->handle($rule)['raised'])->toBe(0);
});

it('runs from the sweep and records what it did', function (): void {
    cpuRule();
    reading(0.94);

    $summary = app(TaskRegistry::class)->resolve(AutomationTask::Alerts)->handle();

    expect($summary->examined)->toBe(1)
        ->and($summary->changed)->toBe(1);

    // Run it twice and the second changes nothing — the rule every automation
    // task is held to.
    $second = app(TaskRegistry::class)->resolve(AutomationTask::Alerts)->handle();

    expect($second->changed)->toBe(0)
        ->and($second->skipped)->toBe(1);
});

/**
 * Phase 17's rule and the one after it: a screen with no test that renders it
 * has not been tested, and a screen whose actions no test performs has not
 * been tested either.
 */
it('renders the list and the rules', function (): void {
    cpuRule();
    reading(0.94);
    app(TaskRegistry::class)->resolve(AutomationTask::Alerts)->handle();

    $this->actingAs($this->admin, 'staff')
        ->get('/admin/reliability/alerts')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Admin/Reliability/Alerts')
            ->has('alerts.data', 1)
            ->has('rules', 1)
            // The tone is the server's: `AlertSeverity::tone()` is where "an
            // emergency and a critical both read as critical" is decided, and
            // a second mapping in the browser would be a second place to get
            // it wrong.
            ->where('alerts.data.0.severityTone', 'warning')
            // And the wording answers *when*, which is what distinguishes
            // the three. It must not read as `health.states.degraded` does —
            // two scales in adjacent columns wearing the same words look
            // like one scale.
            ->where('alerts.data.0.severityLabel', 'During the day')
            ->where('rules.0.openAlerts', 1)
            // Back out of parts per million in the one place that knows the
            // unit.
            ->where('rules.0.threshold', 0.9)
            ->where('can.manage', true));
});

it('writes a rule from the screen', function (): void {
    $this->actingAs($this->admin, 'staff')
        ->post('/admin/reliability/alert-rules', [
            'name' => 'Memory above 95%',
            'subject' => AlertSubject::Metric->value,
            'target' => MetricKind::MemoryUsed->value,
            'comparison' => AlertComparison::Above->value,
            'threshold' => '0.95',
            'for_minutes' => 5,
            'severity' => AlertSeverity::Critical->value,
            'enabled' => true,
            'notify' => true,
        ])
        // `assertSessionHasNoErrors` alone passes against a 403 and a 404.
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $rule = AlertRule::query()->sole();

    // The conversion happens once, in the request. A number converted in two
    // places is a number the two places eventually disagree about.
    expect($rule->threshold_ppm)->toBe(950_000)
        ->and($rule->threshold())->toBe(0.95)
        ->and($rule->severity)->toBe(AlertSeverity::Critical);
});

/** `numeric` would accept `1e2`, which is a hundred written by nobody. */
it('refuses a threshold in scientific notation', function (): void {
    $this->actingAs($this->admin, 'staff')
        ->post('/admin/reliability/alert-rules', [
            'name' => 'Nonsense',
            'subject' => AlertSubject::Metric->value,
            'target' => MetricKind::MemoryUsed->value,
            'comparison' => AlertComparison::Above->value,
            'threshold' => '1e2',
            'for_minutes' => 0,
            'severity' => AlertSeverity::Warning->value,
        ])
        ->assertRedirect()
        ->assertSessionHasErrors('threshold');

    expect(AlertRule::query()->count())->toBe(0);
});

/**
 * A subject that is not a number carries neither comparison nor threshold —
 * a rule that stored "above 90" beside "the scheduler has stopped" would be a
 * rule whose screen could not describe it.
 */
it('drops the threshold on a rule that is not about a number', function (): void {
    $this->actingAs($this->admin, 'staff')
        ->post('/admin/reliability/alert-rules', [
            'name' => 'The queue check',
            'subject' => AlertSubject::HealthCheck->value,
            'target' => 'queue',
            'comparison' => AlertComparison::Above->value,
            'threshold' => '90',
            'for_minutes' => 0,
            'severity' => AlertSeverity::Critical->value,
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $rule = AlertRule::query()->sole();

    expect($rule->threshold_ppm)->toBeNull()
        ->and($rule->comparison)->toBeNull();
});

it('deletes a rule from the screen', function (): void {
    $rule = cpuRule();

    $this->actingAs($this->admin, 'staff')
        ->delete('/admin/reliability/alert-rules/'.$rule->id)
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect(AlertRule::query()->count())->toBe(0);
});

/** Support answers "is it just me?" and does not rewrite the thresholds. */
it('lets support read the list and offers them no rules to write', function (): void {
    $agent = StaffUser::factory()->create();
    $agent->assignRole(SystemRole::Support);

    $this->actingAs($agent->fresh(), 'staff')
        ->get('/admin/reliability/alerts')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('can.manage', false));

    $this->actingAs($agent->fresh(), 'staff')
        ->post('/admin/reliability/alert-rules', [
            'name' => 'Mine now',
            'subject' => AlertSubject::HealthCheck->value,
            'target' => 'queue',
            'for_minutes' => 0,
            'severity' => AlertSeverity::Warning->value,
        ])
        ->assertForbidden();
});

it('leaves a disabled rule alone', function (): void {
    cpuRule(['enabled' => false]);
    reading(0.99);

    $summary = app(TaskRegistry::class)->resolve(AutomationTask::Alerts)->handle();

    expect($summary->examined)->toBe(0)
        ->and(Alert::query()->count())->toBe(0);
});
