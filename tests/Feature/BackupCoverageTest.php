<?php

declare(strict_types=1);

use App\Application\Access\SyncPermissions;
use App\Application\Automation\TaskRegistry;
use App\Application\Infrastructure\BackupCoverage;
use App\Application\Infrastructure\RecordProtections;
use App\Domain\Access\PermissionRegistry;
use App\Domain\Access\SystemRole;
use App\Domain\Automation\AutomationTask;
use App\Domain\Infrastructure\Backup\BackupOutcome;
use App\Domain\Infrastructure\Backup\ProtectedResource;
use App\Domain\Organizations\OrganizationType;
use App\Domain\Provisioning\ServiceStatus;
use App\Domain\Reliability\AlertComparison;
use App\Domain\Reliability\AlertSeverity;
use App\Domain\Reliability\AlertState;
use App\Domain\Reliability\AlertSubject;
use App\Infrastructure\Backup\Models\BackupProtection;
use App\Infrastructure\Crm\Models\Customer;
use App\Infrastructure\Identity\Models\StaffUser;
use App\Infrastructure\Organizations\Models\Organization;
use App\Infrastructure\Provisioning\Models\Service;
use App\Infrastructure\Reliability\Models\Alert;
use App\Infrastructure\Reliability\Models\AlertRule;
use App\Support\Organizations\OrganizationContext;
use Carbon\CarbonImmutable;
use Database\Seeders\ProviderOrganizationSeeder;
use Database\Seeders\SystemRoleSeeder;

/**
 * Backup coverage (§12).
 *
 * **Core never takes a backup**, and what it can answer instead is the thing
 * no backup vendor can: which of the services this installation sold nothing
 * is protecting. Veeam knows what it backs up; only this installation knows
 * what exists.
 *
 * The dangerous failure in this family is a source that is merely down
 * reporting nothing, being read as every customer having lost their backups.
 * Two rules stop it, and both are tested here: a failed read retires nothing,
 * and stale is kept apart from unprotected.
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

    $this->record = app(RecordProtections::class);
    $this->coverage = app(BackupCoverage::class);
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

/** A customer with one service, named and domained as given. */
function backedUpService(string $company, string $name, ?string $domain = null): Service
{
    return app(OrganizationContext::class)->withoutBoundary(static function () use ($company, $name, $domain): Service {
        $customer = Customer::factory()->create(['company_name' => $company]);

        return Service::factory()->create([
            'organization_id' => $customer->organization_id,
            'customer_id' => $customer->id,
            'status' => ServiceStatus::Active->value,
            'name' => $name,
            'domain' => $domain,
        ]);
    });
}

function protectedResource(string $key, string $name, ?CarbonImmutable $lastGood = null): ProtectedResource
{
    return new ProtectedResource(
        key: $key,
        name: $name,
        lastOutcome: BackupOutcome::Succeeded,
        lastRunAt: CarbonImmutable::now(),
        lastGoodAt: $lastGood ?? CarbonImmutable::now(),
        restorePoints: 14,
    );
}

it('records what a source is protecting and matches it to a service', function (): void {
    $service = backedUpService('A customer', 'Hosting', 'example.com');

    $outcome = $this->record->handle($this->provider->id, 'test-backup', [
        protectedResource('job-1', 'example.com'),
    ]);

    $protection = BackupProtection::query()->sole();

    expect($outcome['recorded'])->toBe(1)
        ->and($outcome['matched'])->toBe(1)
        ->and($protection->service_id)->toBe($service->id)
        ->and($protection->customer_id)->toBe($service->customer_id)
        ->and($protection->last_outcome)->toBe(BackupOutcome::Succeeded);
});

/** The service's own name, when the source does not use the domain. */
it('matches on the service name as well as the domain', function (): void {
    $service = backedUpService('A customer', 'acct-4471', 'example.com');

    $this->record->handle($this->provider->id, 'test-backup', [
        protectedResource('job-1', 'acct-4471'),
    ]);

    expect(BackupProtection::query()->sole()->service_id)->toBe($service->id);
});

/**
 * A protection attached to the wrong service is worse than one nobody
 * placed: the first is what somebody reads before telling a customer their
 * site is backed up.
 */
it('refuses an ambiguous name rather than picking one', function (): void {
    backedUpService('First', 'shared', 'one.example.com');
    backedUpService('Second', 'shared', 'two.example.com');

    $outcome = $this->record->handle($this->provider->id, 'test-backup', [
        protectedResource('job-1', 'shared'),
    ]);

    expect($outcome['matched'])->toBe(0)
        ->and(BackupProtection::query()->sole()->service_id)->toBeNull();
});

/**
 * A job for a customer who left is worth knowing about, and worth money.
 */
it('keeps a protection that matches no service', function (): void {
    $outcome = $this->record->handle($this->provider->id, 'test-backup', [
        protectedResource('job-1', 'nobody-here.example.com'),
    ]);

    expect($outcome['recorded'])->toBe(1)
        ->and($outcome['matched'])->toBe(0)
        ->and(BackupProtection::query()->sole()->resource_name)->toBe('nobody-here.example.com');
});

it('retires what a source has stopped naming, and keeps the row', function (): void {
    $this->record->handle($this->provider->id, 'test-backup', [
        protectedResource('job-1', 'one.example.com'),
        protectedResource('job-2', 'two.example.com'),
    ]);

    $outcome = $this->record->handle($this->provider->id, 'test-backup', [
        protectedResource('job-1', 'one.example.com'),
    ]);

    expect($outcome['retired'])->toBe(1)
        ->and(BackupProtection::query()->count())->toBe(2)
        ->and(BackupProtection::query()->live()->sole()->resource_key)->toBe('job-1');
});

/**
 * During a migration between two backup vendors, having both is the point.
 */
it('retires only what its own source wrote', function (): void {
    $this->record->handle($this->provider->id, 'old-vendor', [protectedResource('a', 'one.example.com')]);
    $this->record->handle($this->provider->id, 'new-vendor', [protectedResource('b', 'one.example.com')]);

    $this->record->handle($this->provider->id, 'old-vendor', []);

    expect(BackupProtection::query()->live()->sole()->source)->toBe('new-vendor');
});

/** A resource that leaves a job and comes back is protected again. */
it('un-retires a resource a source starts naming again', function (): void {
    $this->record->handle($this->provider->id, 'test-backup', [protectedResource('job-1', 'one.example.com')]);
    $this->record->handle($this->provider->id, 'test-backup', []);
    $this->record->handle($this->provider->id, 'test-backup', [protectedResource('job-1', 'one.example.com')]);

    expect(BackupProtection::query()->count())->toBe(1)
        ->and(BackupProtection::query()->sole()->retired_at)->toBeNull();
});

/**
 * "There has never been a good copy" and "I cannot tell you about the last
 * one" are different answers, and writing the first when it meant the second
 * makes a protected service look abandoned.
 */
it('never erases a last good copy a later run could not describe', function (): void {
    CarbonImmutable::setTestNow('2026-09-20 02:00:00');

    $this->record->handle($this->provider->id, 'test-backup', [
        protectedResource('job-1', 'one.example.com', CarbonImmutable::parse('2026-09-20 02:00:00')),
    ]);

    CarbonImmutable::setTestNow('2026-09-21 02:00:00');

    $this->record->handle($this->provider->id, 'test-backup', [
        new ProtectedResource(
            key: 'job-1',
            name: 'one.example.com',
            lastOutcome: BackupOutcome::Failed,
            lastRunAt: CarbonImmutable::now(),
        ),
    ]);

    $protection = BackupProtection::query()->sole();

    expect($protection->last_outcome)->toBe(BackupOutcome::Failed)
        ->and($protection->last_good_at?->toDateString())->toBe('2026-09-20');
});

/**
 * Stale and unprotected are different problems and the distinction is the
 * safety rail: a source that is down must not read as every customer having
 * lost their backups.
 */
it('tells stale apart from unprotected', function (): void {
    CarbonImmutable::setTestNow('2026-09-25 09:00:00');

    backedUpService('Fine', 'Hosting A', 'fine.example.com');
    backedUpService('Stale', 'Hosting B', 'stale.example.com');
    backedUpService('Nobody', 'Hosting C', 'forgotten.example.com');

    $this->record->handle($this->provider->id, 'test-backup', [
        protectedResource('job-1', 'fine.example.com'),
        protectedResource('job-2', 'stale.example.com', CarbonImmutable::parse('2026-09-10 02:00:00')),
    ]);

    expect($this->coverage->summary(2))->toBe([
        'protected' => 1,
        'stale' => 1,
        'unprotected' => 1,
    ]);

    expect($this->coverage->unprotected()->total())->toBe(1)
        ->and($this->coverage->unprotected()->items()[0]->domain)->toBe('forgotten.example.com')
        ->and($this->coverage->stale(2)->total())->toBe(1)
        ->and($this->coverage->healthy(2)->total())->toBe(1);
});

/**
 * **Each figure counts what its own list shows**, which is not the same unit
 * for all three: pressing a count filters to a list, so a figure that did not
 * match the rows under it would read as a bug. Unprotected counts services;
 * stale and protected count jobs, because a broken job is the thing to fix.
 *
 * Two sources protecting one service, one of them broken, is therefore one
 * fresh job and one stale one — and the service is not unprotected, because
 * it can still be restored.
 */
it('counts services for unprotected and jobs for the other two', function (): void {
    CarbonImmutable::setTestNow('2026-09-25 09:00:00');

    backedUpService('Two sources', 'Hosting', 'both.example.com');

    $this->record->handle($this->provider->id, 'old-vendor', [
        protectedResource('a', 'both.example.com', CarbonImmutable::parse('2026-08-01 02:00:00')),
    ]);
    $this->record->handle($this->provider->id, 'new-vendor', [
        protectedResource('b', 'both.example.com'),
    ]);

    expect($this->coverage->summary(2))->toBe([
        'protected' => 1,
        'stale' => 1,
        'unprotected' => 0,
    ])
        // And the figures match their lists exactly, which is the contract a
        // pressable count makes.
        ->and($this->coverage->stale(2)->total())->toBe(1)
        ->and($this->coverage->healthy(2)->total())->toBe(1)
        ->and($this->coverage->unprotected()->total())->toBe(0);
});

/**
 * A terminated service has nothing to back up, and listing it would bury the
 * four that matter under four hundred that do not.
 */
it('expects no backup of a service that is not running', function (): void {
    app(OrganizationContext::class)->withoutBoundary(static function (): void {
        $customer = Customer::factory()->create(['company_name' => 'Gone']);

        Service::factory()->create([
            'organization_id' => $customer->organization_id,
            'customer_id' => $customer->id,
            'status' => ServiceStatus::Terminated->value,
            'name' => 'Old hosting',
        ]);
    });

    expect($this->coverage->summary(2)['unprotected'])->toBe(0)
        ->and($this->coverage->unprotected()->total())->toBe(0);
});

/**
 * A suspended service still holds the customer's data and is the one most
 * likely to be deleted next.
 */
it('still expects a backup of a suspended service', function (): void {
    app(OrganizationContext::class)->withoutBoundary(static function (): void {
        $customer = Customer::factory()->create(['company_name' => 'Behind on the bill']);

        Service::factory()->create([
            'organization_id' => $customer->organization_id,
            'customer_id' => $customer->id,
            'status' => ServiceStatus::Suspended->value,
            'name' => 'Hosting',
        ]);
    });

    expect($this->coverage->summary(2)['unprotected'])->toBe(1);
});

/**
 * The worst thing this family could do: a token expires, the source answers
 * nothing, and every customer appears to have lost their backups.
 */
it('retires nothing when no backup source is configured', function (): void {
    $this->record->handle($this->provider->id, 'test-backup', [
        protectedResource('job-1', 'one.example.com'),
    ]);

    app(TaskRegistry::class)->resolve(AutomationTask::Backups)->handle();

    expect(BackupProtection::query()->live()->count())->toBe(1);
});

it('raises an alert on a last good copy older than the operator’s threshold', function (): void {
    CarbonImmutable::setTestNow('2026-09-25 09:00:00');

    BackupProtection::factory()->lastGoodDaysAgo(9)->create([
        'organization_id' => $this->provider->id,
        'resource_name' => 'old.example.com',
    ]);

    BackupProtection::factory()->lastGoodDaysAgo(1)->create([
        'organization_id' => $this->provider->id,
        'resource_name' => 'fine.example.com',
    ]);

    AlertRule::factory()->create([
        'organization_id' => $this->provider->id,
        'subject' => AlertSubject::BackupAge,
        'target' => null,
        'comparison' => AlertComparison::Above,
        // Two days, which is the operator's number. Core ships no rules.
        'threshold_ppm' => 2_000_000,
        'for_minutes' => 0,
        'severity' => AlertSeverity::Critical,
        'enabled' => true,
        'notify' => false,
    ]);

    app(TaskRegistry::class)->resolve(AutomationTask::Alerts)->handle();

    $alert = Alert::query()->sole();

    expect($alert->state)->toBe(AlertState::Raised)
        ->and($alert->subject_label)->toBe('old.example.com');
});

/**
 * A resource added to a job this afternoon has never had a good copy and has
 * not failed. An age invented for it would be a number somebody acts on.
 */
it('does not alert on a protection that has never succeeded', function (): void {
    BackupProtection::factory()->neverRun()->create([
        'organization_id' => $this->provider->id,
        'resource_name' => 'brand-new.example.com',
    ]);

    AlertRule::factory()->create([
        'organization_id' => $this->provider->id,
        'subject' => AlertSubject::BackupAge,
        'target' => null,
        'comparison' => AlertComparison::Above,
        'threshold_ppm' => 0,
        'for_minutes' => 0,
        'severity' => AlertSeverity::Critical,
        'enabled' => true,
        'notify' => false,
    ]);

    app(TaskRegistry::class)->resolve(AutomationTask::Alerts)->handle();

    expect(Alert::query()->count())->toBe(0);
});

/** It is still listed as stale, because "nothing yet" is what to show. */
it('lists a protection that has never succeeded as stale', function (): void {
    BackupProtection::factory()->neverRun()->create([
        'organization_id' => $this->provider->id,
        'resource_name' => 'brand-new.example.com',
    ]);

    expect($this->coverage->stale(2)->total())->toBe(1)
        ->and($this->coverage->summary(2)['stale'])->toBe(1);
});

it('drives the screen, opening on what nothing protects', function (): void {
    backedUpService('Nobody', 'Hosting', 'forgotten.example.com');

    $this->actingAs($this->admin, 'staff')
        ->get('/admin/infrastructure/backups')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Admin/Infrastructure/BackupCoverage')
            ->where('tab', 'unprotected')
            ->where('summary.unprotected', 1)
            ->has('rows.data', 1)
            ->where('rows.data.0.domain', 'forgotten.example.com')
            ->has('rows.links'));
});

it('drives the stale list', function (): void {
    CarbonImmutable::setTestNow('2026-09-25 09:00:00');

    BackupProtection::factory()->failing()->create([
        'organization_id' => $this->provider->id,
        'resource_name' => 'old.example.com',
    ]);

    $this->actingAs($this->admin, 'staff')
        ->get('/admin/infrastructure/backups?tab=stale')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Admin/Infrastructure/BackupCoverage')
            ->where('tab', 'stale')
            ->has('rows.data', 1)
            ->where('rows.data.0.name', 'old.example.com')
            // The tone crosses as its own field, never derived from a label
            // — and it is a word `status.ts` knows, which `VocabularyTest`
            // now pins for every enum in the product.
            ->where('rows.data.0.outcomeTone', 'critical'));
});

it('refuses somebody without the permission', function (): void {
    $nobody = StaffUser::factory()->create();

    $this->actingAs($nobody->fresh(), 'staff')
        ->get('/admin/infrastructure/backups')
        ->assertForbidden();
});

/** Every outcome an operator reads is named, in both languages. */
it('names every backup outcome in both languages', function (): void {
    foreach (['en', 'tr'] as $locale) {
        app()->setLocale($locale);

        foreach (BackupOutcome::cases() as $outcome) {
            expect(__($outcome->labelKey()))->not->toBe($outcome->labelKey());
        }
    }

    app()->setLocale('en');
});
