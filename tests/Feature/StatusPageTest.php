<?php

declare(strict_types=1);

use App\Application\Health\MaintenanceMode;
use App\Application\Reliability\Incidents;
use App\Application\Reliability\MaintenanceWindows;
use App\Application\Reliability\PublicStatus;
use App\Domain\Organizations\OrganizationType;
use App\Domain\Reliability\AlertSeverity;
use App\Domain\Reliability\IncidentState;
use App\Domain\Reliability\PublicStatusLevel;
use App\Infrastructure\Identity\Models\StaffUser;
use App\Infrastructure\Organizations\Models\Organization;
use App\Infrastructure\Reliability\Models\Incident;
use App\Infrastructure\Reliability\Models\IncidentUpdate;
use App\Infrastructure\Reliability\Models\MaintenanceWindow;
use App\Support\Organizations\OrganizationContext;
use Carbon\CarbonImmutable;
use Database\Seeders\ProviderOrganizationSeeder;

/**
 * The public status page (§15).
 *
 * Four things about it are the design rather than the implementation:
 *
 * **It stays up in maintenance mode.** Closing the one page whose purpose is
 * to be readable while something is wrong would tell a customer nothing they
 * could not already see.
 *
 * **Publishing is a decision somebody made**, per incident *and* per update.
 * An incident nobody marked public does not appear, and an internal note in
 * the middle of a public incident does not either.
 *
 * **What is published is short**, and the test asserts the absence: no impact
 * figure, no alert subject, no operator's name. "47 customers, 12,400 EUR a
 * month" tells the internet the size of the business and which outage was the
 * expensive one.
 *
 * **The good state is a sentence.** A status page whose healthy state is a
 * blank space is a page a customer cannot tell from one that failed to load.
 */
beforeEach(function (): void {
    $this->seed(ProviderOrganizationSeeder::class);

    $this->provider = Organization::query()
        ->where('type', OrganizationType::Provider->value)
        ->firstOrFail();

    app(OrganizationContext::class)->set($this->provider->id);

    $this->staff = StaffUser::factory()->create(['name' => 'Ayse Yilmaz']);
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

it('says all systems operational when nothing is open', function (): void {
    $this->get('/status')
        ->assertOk()
        ->assertSee(__('reliability.status_page.levels.operational'))
        ->assertSee(__('reliability.status_page.no_history', [
            'days' => PublicStatus::HistoryDays,
        ]));
});

it('shows a public incident and the updates somebody published', function (): void {
    $incident = app(Incidents::class)->open(
        organizationId: $this->provider->id,
        title: 'Writes failing on the database primary',
        body: 'Customers on the shared cluster are seeing errors.',
        severity: AlertSeverity::Critical,
        actor: $this->staff,
        public: true,
    );

    app(Incidents::class)->note(
        $incident,
        'A full data volume. Clearing old binlogs.',
        IncidentState::Identified,
        $this->staff,
        public: true,
    );

    // Written to the next operator, not to a customer.
    app(Incidents::class)->note(
        $incident,
        'The failover did not work, trying the secondary.',
        IncidentState::Monitoring,
        $this->staff,
        public: false,
    );

    $this->get('/status')
        ->assertOk()
        ->assertSee(__('reliability.status_page.levels.disrupted'))
        ->assertSee(__('reliability.status_page.happening_now'))
        ->assertSee('Writes failing on the database primary')
        ->assertSee('Customers on the shared cluster are seeing errors.')
        ->assertSee('A full data volume. Clearing old binlogs.')
        ->assertDontSee('The failover did not work')
        // Never the operator's name: a customer does not need it and the
        // operator did not agree to be named on a public page.
        ->assertDontSee('Ayse Yilmaz')
        // Nor the reference, which is the seller's document number.
        ->assertDontSee($incident->reference);
});

it('publishes nothing about an incident nobody made public', function (): void {
    $incident = app(Incidents::class)->open(
        organizationId: $this->provider->id,
        title: 'A private thing that went wrong',
        body: 'Only we know about this.',
        severity: AlertSeverity::Emergency,
        actor: $this->staff,
    );

    $this->get('/status')
        ->assertOk()
        ->assertSee(__('reliability.status_page.levels.operational'))
        ->assertDontSee('A private thing')
        ->assertDontSee('Only we know');

    expect($incident->is_public)->toBeFalse();
});

/** An emergency is the banner a customer needs, not "some systems". */
it('calls an emergency an outage', function (): void {
    app(Incidents::class)->open(
        organizationId: $this->provider->id,
        title: 'Everything is down',
        body: 'We are looking at it.',
        severity: AlertSeverity::Emergency,
        actor: $this->staff,
        public: true,
    );

    $this->get('/status')
        ->assertOk()
        ->assertSee(__('reliability.status_page.levels.outage'))
        ->assertDontSee(__('reliability.status_page.levels.disrupted'));
});

it('moves a resolved incident into the history', function (): void {
    $incident = app(Incidents::class)->open(
        organizationId: $this->provider->id,
        title: 'Mail queue stopped',
        body: 'Nothing has left the queue.',
        severity: AlertSeverity::Critical,
        actor: $this->staff,
        public: true,
    );

    app(Incidents::class)->resolve($incident, 'A stuck worker. Restarted.', $this->staff, public: true);

    $response = $this->get('/status')->assertOk();

    $response->assertSee(__('reliability.status_page.levels.operational'))
        ->assertSee(__('reliability.status_page.history'))
        ->assertSee('Mail queue stopped')
        ->assertDontSee(__('reliability.status_page.happening_now'));
});

/**
 * Ninety days, and the boundary is the rows rather than the clock — an
 * incident from last year is history somebody has stopped needing.
 */
it('leaves an old resolved incident out of the history', function (): void {
    $incident = Incident::factory()
        ->inState(IncidentState::Resolved)
        ->published()
        ->create([
            'organization_id' => $this->provider->id,
            'title' => 'Something from last spring',
            'started_at' => CarbonImmutable::now()->subDays(200),
            'resolved_at' => CarbonImmutable::now()->subDays(200)->addHour(),
        ]);

    IncidentUpdate::factory()->create([
        'organization_id' => $this->provider->id,
        'incident_id' => $incident->id,
        'is_public' => true,
    ]);

    $this->get('/status')
        ->assertOk()
        ->assertDontSee('Something from last spring');
});

/**
 * The one page that must not close with the shop. A customer who goes there
 * to find out whether anything is down, and is told the site is down for
 * maintenance, has learned nothing.
 */
it('stays up while maintenance mode closes the shop', function (): void {
    app(MaintenanceMode::class)->enable('Back at six.');

    $this->get('/')->assertStatus(503);

    $this->get('/status')
        ->assertOk()
        ->assertSee(__('reliability.status_page.levels.operational'));
});

/**
 * Planned work, above the incidents: somebody who has just noticed their site
 * is slow wants to know whether it was announced before they read about what
 * broke.
 */
it('announces the planned work somebody published', function (): void {
    MaintenanceWindow::factory()
        ->published()
        ->create([
            'organization_id' => $this->provider->id,
            'title' => 'Switch firmware on the core pair',
            'body' => 'Sites stay up. Expect two short interruptions.',
        ]);

    MaintenanceWindow::factory()->create([
        'organization_id' => $this->provider->id,
        'title' => 'A window nobody published',
    ]);

    $this->get('/status')
        ->assertOk()
        ->assertSee(__('reliability.status_page.maintenance'))
        ->assertSee('Switch firmware on the core pair')
        ->assertSee('Sites stay up. Expect two short interruptions.')
        ->assertDontSee('A window nobody published')
        // **The banner does not move.** "All systems operational" during
        // planned work is the truth as far as a customer standing outside is
        // concerned, and a page that went amber every Sunday at two is one
        // nobody reads on a Monday.
        ->assertSee(__('reliability.status_page.levels.operational'));
});

it('stops announcing a window that was called off', function (): void {
    $window = MaintenanceWindow::factory()
        ->published()
        ->create([
            'organization_id' => $this->provider->id,
            'title' => 'Switch firmware on the core pair',
        ]);

    app(MaintenanceWindows::class)->cancel($window, $this->staff);

    $this->get('/status')
        ->assertOk()
        ->assertDontSee('Switch firmware on the core pair');
});

/** A window that is over is an operator's record, not an announcement. */
it('does not announce a window that has already run', function (): void {
    MaintenanceWindow::factory()
        ->published()
        ->over()
        ->create([
            'organization_id' => $this->provider->id,
            'title' => 'Last night on the core pair',
        ]);

    $this->get('/status')
        ->assertOk()
        ->assertDontSee('Last night on the core pair');
});

it('answers 404 when the installation publishes no status page', function (): void {
    config(['platform.reliability.status_page' => false]);

    $this->get('/status')->assertNotFound();
});

/** Both banners are named, or a customer reads a key at the worst moment. */
it('names every level in both languages', function (): void {
    foreach (['en', 'tr'] as $locale) {
        app()->setLocale($locale);

        foreach (PublicStatusLevel::cases() as $level) {
            expect(__($level->labelKey()))->not->toBe($level->labelKey());
        }
    }

    app()->setLocale('en');
});
