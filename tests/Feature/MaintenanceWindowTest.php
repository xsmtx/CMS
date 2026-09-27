<?php

declare(strict_types=1);

use App\Application\Access\SyncPermissions;
use App\Application\Automation\TaskRegistry;
use App\Application\Infrastructure\ResourceGraph;
use App\Application\Reliability\MaintenanceWindows;
use App\Domain\Access\PermissionRegistry;
use App\Domain\Access\SystemRole;
use App\Domain\Automation\AutomationTask;
use App\Domain\Infrastructure\MetricKind;
use App\Domain\Infrastructure\MetricUnit;
use App\Domain\Infrastructure\ResourceKind;
use App\Domain\Notifications\NotificationEvent;
use App\Domain\Organizations\OrganizationType;
use App\Domain\Reliability\AlertComparison;
use App\Domain\Reliability\AlertSeverity;
use App\Domain\Reliability\AlertState;
use App\Domain\Reliability\AlertSubject;
use App\Domain\Reliability\Exceptions\MaintenanceRefused;
use App\Infrastructure\Identity\Models\StaffUser;
use App\Infrastructure\Notifications\Models\NotificationDelivery;
use App\Infrastructure\Organizations\Models\Organization;
use App\Infrastructure\Reliability\Models\Alert;
use App\Infrastructure\Reliability\Models\AlertRule;
use App\Infrastructure\Reliability\Models\MaintenanceWindow;
use App\Infrastructure\Resources\Models\ResourceMetric;
use App\Support\Organizations\OrganizationContext;
use Carbon\CarbonImmutable;
use Database\Seeders\ProviderOrganizationSeeder;
use Database\Seeders\SystemRoleSeeder;

/**
 * Maintenance windows (§16), and the notifications they hold back.
 *
 * **A window suppresses the message, never the observation.** The alert is
 * raised, counted and on the screen exactly as it would be, carrying the
 * window that held it — an operator asking "did anything happen during the
 * maintenance" has to get the true answer, and a platform that dropped the
 * reading could not give one.
 *
 * **There is no state column.** Whether a window is running is a question
 * about its own two timestamps, so a scheduler that was down for three hours
 * cannot leave one marked "scheduled" while it is plainly happening. Being
 * called off is the one thing a clock cannot say, and that is a column.
 *
 * **Empty node keys mean everywhere**, which is what a datacentre power test
 * is — and is the case somebody writing the check by hand gets backwards.
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

/** A disk reading bad enough to raise the rule below. */
function badReading(float $value = 0.94): ResourceMetric
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
            'sampled_at' => CarbonImmutable::now(),
            'stale_after_seconds' => 300,
            'source' => 'test',
        ],
    );
}

function noisyRule(bool $notify = true): AlertRule
{
    return AlertRule::factory()->create([
        'organization_id' => test()->provider->id,
        'subject' => AlertSubject::Metric,
        'target' => MetricKind::CpuUtilisation->value,
        'comparison' => AlertComparison::Above,
        'threshold_ppm' => 900_000,
        'for_minutes' => 0,
        'severity' => AlertSeverity::Critical,
        'enabled' => true,
        'notify' => $notify,
    ]);
}

function sweep(): void
{
    app(TaskRegistry::class)->resolve(AutomationTask::Alerts)->handle();
}

it('raises the alert anyway and records which window held the message', function (): void {
    $window = MaintenanceWindow::factory()
        ->running()
        ->create(['organization_id' => $this->provider->id]);

    noisyRule();
    badReading();
    sweep();

    $alert = Alert::query()->sole();

    // Raised, counted, and visible. Only the message was held.
    expect($alert->state)->toBe(AlertState::Raised)
        ->and($alert->suppressed_by)->toBe($window->id)
        ->and(NotificationDelivery::query()->count())->toBe(0);
});

it('sends the message when no window is running', function (): void {
    noisyRule();
    badReading();
    sweep();

    $alert = Alert::query()->sole();

    expect($alert->suppressed_by)->toBeNull();

    $delivery = NotificationDelivery::query()
        ->where('event', NotificationEvent::AlertRaised->value)
        ->first();

    expect($delivery)->not->toBeNull();
});

/**
 * A window that names machines holds back only those machines. The obvious
 * bug is the opposite: a window for one rack silencing the whole estate.
 */
it('holds back only the machines it names', function (): void {
    MaintenanceWindow::factory()
        ->running()
        ->covering(['db-9.dc1'])
        ->create(['organization_id' => $this->provider->id]);

    noisyRule();
    badReading();
    sweep();

    expect(Alert::query()->sole()->suppressed_by)->toBeNull();
});

/** Empty means everywhere, which is what a power test is. */
it('holds back everything when it names nothing', function (): void {
    $window = MaintenanceWindow::factory()
        ->running()
        ->create(['organization_id' => $this->provider->id, 'node_keys' => []]);

    noisyRule();
    badReading();
    sweep();

    expect(Alert::query()->sole()->suppressed_by)->toBe($window->id);
});

it('holds nothing back once it has been called off', function (): void {
    $window = MaintenanceWindow::factory()
        ->running()
        ->create(['organization_id' => $this->provider->id]);

    app(MaintenanceWindows::class)->cancel($window, $this->admin);

    noisyRule();
    badReading();
    sweep();

    expect(Alert::query()->sole()->suppressed_by)->toBeNull();
});

/**
 * `notify` was stored, shown on the rule form and read by nothing until the
 * alert event existed — the "a setting stored and read by nothing" trap,
 * shipped once in this very context.
 */
it('sends nothing at all for a rule with notifications off', function (): void {
    noisyRule(notify: false);
    badReading();
    sweep();

    expect(Alert::query()->sole()->state)->toBe(AlertState::Raised)
        ->and(NotificationDelivery::query()->count())->toBe(0);
});

/** A warning that woke somebody is a warning they turn off. */
it('does not interrupt anybody for a warning', function (): void {
    $rule = noisyRule();
    $rule->severity = AlertSeverity::Warning;
    $rule->save();

    badReading();
    sweep();

    expect(Alert::query()->sole()->state)->toBe(AlertState::Raised)
        ->and(NotificationDelivery::query()->count())->toBe(0);
});

/** One alert is one message, however many times the disk crosses the line. */
it('says it once however many times the reading repeats', function (): void {
    noisyRule();
    badReading();

    sweep();

    // However many staff there are to tell, telling them again is the bug —
    // so the claim is that the second and third sweeps add nothing, not that
    // exactly one row exists.
    $afterFirst = NotificationDelivery::query()
        ->where('event', NotificationEvent::AlertRaised->value)
        ->count();

    sweep();
    sweep();

    expect($afterFirst)->toBeGreaterThan(0)
        ->and(Alert::query()->sole()->occurrences)->toBe(3)
        ->and(NotificationDelivery::query()
            ->where('event', NotificationEvent::AlertRaised->value)
            ->count())->toBe($afterFirst);
});

it('is active by its own clock, never by a stored state', function (): void {
    CarbonImmutable::setTestNow('2026-09-27 12:00:00');

    $window = MaintenanceWindow::factory()->create([
        'organization_id' => $this->provider->id,
        'starts_at' => CarbonImmutable::parse('2026-09-27 13:00:00'),
        'ends_at' => CarbonImmutable::parse('2026-09-27 15:00:00'),
    ]);

    expect($window->isActive())->toBeFalse();

    // Nothing ran, nothing was updated, and it is now happening.
    CarbonImmutable::setTestNow('2026-09-27 14:00:00');

    expect($window->isActive())->toBeTrue();

    CarbonImmutable::setTestNow('2026-09-27 16:00:00');

    expect($window->isActive())->toBeFalse()
        ->and($window->hasEnded())->toBeTrue();
});

it('refuses a window that ends before it starts', function (): void {
    expect(fn (): MaintenanceWindow => app(MaintenanceWindows::class)->schedule(
        organizationId: $this->provider->id,
        title: 'Backwards',
        startsAt: CarbonImmutable::now()->addHours(2),
        endsAt: CarbonImmutable::now()->addHour(),
    ))->toThrow(MaintenanceRefused::class);
});

/**
 * Cancelling one that already ran would be rewriting what happened — and the
 * alerts it suppressed carry its id.
 */
it('refuses to call off a window that is over', function (): void {
    $window = MaintenanceWindow::factory()
        ->over()
        ->create(['organization_id' => $this->provider->id]);

    expect(fn (): MaintenanceWindow => app(MaintenanceWindows::class)->cancel($window, $this->admin))
        ->toThrow(MaintenanceRefused::class);
});

it('drives the screen', function (): void {
    $running = MaintenanceWindow::factory()
        ->running()
        ->create(['organization_id' => $this->provider->id]);

    MaintenanceWindow::factory()->create(['organization_id' => $this->provider->id]);

    $this->actingAs($this->admin, 'staff')
        ->get('/admin/reliability/maintenance')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Admin/Reliability/Maintenance')
            ->has('windows', 2)
            // The state is derived, and the tone is the server's.
            ->where('windows.0.state', 'scheduled')
            ->where('windows.1.state', 'active')
            ->where('windows.1.stateTone', 'maintenance'));

    $this->actingAs($this->admin, 'staff')
        ->post('/admin/reliability/maintenance', [
            'title' => 'Switch firmware',
            'starts_at' => CarbonImmutable::now()->addDay()->toDateTimeString(),
            'ends_at' => CarbonImmutable::now()->addDay()->addHours(2)->toDateTimeString(),
            'node_keys' => "web-1.dc2\n\nweb-2.dc2\n",
            'is_public' => true,
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $written = MaintenanceWindow::query()->where('title', 'Switch firmware')->sole();

    // One per line, blanks dropped: an operator pastes a column out of a
    // spreadsheet and the trailing newline is not a machine.
    expect($written->node_keys)->toBe(['web-1.dc2', 'web-2.dc2'])
        ->and($written->is_public)->toBeTrue();

    $this->actingAs($this->admin, 'staff')
        ->delete('/admin/reliability/maintenance/'.$running->id)
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect($running->fresh()?->cancelled_at)->not->toBeNull();
});

it('refuses the form when it ends before it starts', function (): void {
    $this->actingAs($this->admin, 'staff')
        ->post('/admin/reliability/maintenance', [
            'title' => 'Backwards',
            'starts_at' => CarbonImmutable::now()->addHours(2)->toDateTimeString(),
            'ends_at' => CarbonImmutable::now()->addHour()->toDateTimeString(),
        ])
        ->assertRedirect()
        ->assertSessionHasErrors('ends_at');

    expect(MaintenanceWindow::query()->count())->toBe(0);
});

it('refuses somebody who may not plan work', function (): void {
    $nobody = StaffUser::factory()->create();

    $this->actingAs($nobody->fresh(), 'staff')
        ->get('/admin/reliability/maintenance')
        ->assertForbidden();
});

/** Support answers the phone during a maintenance, so Support can plan one. */
it('lets support plan a window', function (): void {
    $agent = StaffUser::factory()->create();
    $agent->assignRole(SystemRole::Support);

    $this->actingAs($agent->fresh(), 'staff')
        ->get('/admin/reliability/maintenance')
        ->assertOk();
});
