<?php

declare(strict_types=1);

use App\Application\Access\SyncPermissions;
use App\Domain\Access\PermissionRegistry;
use App\Domain\Access\SystemRole;
use App\Domain\Organizations\OrganizationType;
use App\Domain\Reliability\IncidentState;
use App\Infrastructure\Identity\Models\StaffUser;
use App\Infrastructure\Organizations\Models\Organization;
use App\Infrastructure\Reliability\Models\Incident;
use App\Infrastructure\Reliability\Models\MaintenanceWindow;
use App\Support\Organizations\OrganizationContext;
use Carbon\CarbonImmutable;
use Database\Seeders\ProviderOrganizationSeeder;
use Database\Seeders\SystemRoleSeeder;

/**
 * The operations calendar (§16).
 *
 * **An entry spans days.** A window from Friday night to Saturday morning
 * belongs to both, because somebody looking at Saturday needs to know the
 * work was still running at two — and a calendar that filed it under Friday
 * alone would hide exactly the entry they were looking for. The same applies
 * to an incident that ran past midnight, and to one that is still open.
 *
 * **Only days with something on them.** A month is thirty rows of nothing
 * otherwise, and the two that matter are lost in it.
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

    CarbonImmutable::setTestNow('2026-09-15 10:00:00');
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

it('puts a window that runs past midnight on both days', function (): void {
    MaintenanceWindow::factory()->create([
        'organization_id' => $this->provider->id,
        'title' => 'Switch firmware',
        'starts_at' => CarbonImmutable::parse('2026-09-11 23:00:00'),
        'ends_at' => CarbonImmutable::parse('2026-09-12 02:00:00'),
    ]);

    $this->actingAs($this->admin, 'staff')
        ->get('/admin/reliability/calendar')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Admin/Reliability/Calendar')
            ->has('days', 2)
            ->where('days.0.date', '2026-09-11')
            ->where('days.1.date', '2026-09-12')
            ->has('days.0.windows', 1)
            ->has('days.1.windows', 1));
});

it('keeps an open incident on every day since it started', function (): void {
    Incident::factory()->create([
        'organization_id' => $this->provider->id,
        'title' => 'Still going',
        'started_at' => CarbonImmutable::parse('2026-09-13 08:00:00'),
        'resolved_at' => null,
    ]);

    $this->actingAs($this->admin, 'staff')
        ->get('/admin/reliability/calendar')
        ->assertOk()
        // The 13th to the 30th: an incident nobody has resolved is still
        // happening today, and a calendar that stopped mentioning it on the
        // 14th would be one an operator stops trusting.
        ->assertInertia(fn ($page) => $page->has('days', 18));
});

it('shows only the days something happened on', function (): void {
    Incident::factory()
        ->inState(IncidentState::Resolved)
        ->create([
            'organization_id' => $this->provider->id,
            'started_at' => CarbonImmutable::parse('2026-09-03 08:00:00'),
            'resolved_at' => CarbonImmutable::parse('2026-09-03 09:00:00'),
        ]);

    $this->actingAs($this->admin, 'staff')
        ->get('/admin/reliability/calendar')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('days', 1)
            ->where('days.0.date', '2026-09-03'));
});

it('reads the month out of the address bar', function (): void {
    Incident::factory()
        ->inState(IncidentState::Resolved)
        ->create([
            'organization_id' => $this->provider->id,
            'started_at' => CarbonImmutable::parse('2026-07-04 08:00:00'),
            'resolved_at' => CarbonImmutable::parse('2026-07-04 09:00:00'),
        ]);

    $this->actingAs($this->admin, 'staff')
        ->get('/admin/reliability/calendar?month=2026-07')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('month', '2026-07')
            ->where('previous', '2026-06')
            ->where('next', '2026-08')
            ->has('days', 1));
});

/**
 * Somebody edited the address bar. The useful answer is the month they are
 * standing in, not a 422 about a date format.
 */
it('falls back to this month when the parameter is nonsense', function (): void {
    $this->actingAs($this->admin, 'staff')
        ->get('/admin/reliability/calendar?month=last-tuesday')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('month', '2026-09'));
});

it('is empty in a quiet month and says so', function (): void {
    $this->actingAs($this->admin, 'staff')
        ->get('/admin/reliability/calendar')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('days', 0));
});

it('refuses somebody holding neither permission', function (): void {
    $nobody = StaffUser::factory()->create();

    $this->actingAs($nobody->fresh(), 'staff')
        ->get('/admin/reliability/calendar')
        ->assertForbidden();
});
