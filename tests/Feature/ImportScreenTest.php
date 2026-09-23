<?php

declare(strict_types=1);

use App\Application\Access\SyncPermissions;
use App\Domain\Access\PermissionRegistry;
use App\Domain\Access\SystemRole;
use App\Domain\Import\ImportDomain;
use App\Domain\Import\ImportMode;
use App\Domain\Import\ImportOutcome;
use App\Domain\Import\ImportStatus;
use App\Http\Middleware\RequireRecentAuthentication;
use App\Infrastructure\Identity\Models\StaffUser;
use App\Infrastructure\Import\Jobs\RunImportJob;
use App\Infrastructure\Import\Models\ImportItem;
use App\Infrastructure\Import\Models\ImportRun;
use App\Support\Organizations\OrganizationContext;
use Database\Seeders\ProviderOrganizationSeeder;
use Database\Seeders\SystemRoleSeeder;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia;

/**
 * The import screen.
 *
 * Two things are under test and both are about not lying to an operator:
 *
 * - **Owner only.** An import writes customers, invoices and ledger rows
 *   straight into the database, bypassing every use case on purpose, and reads a
 *   second database over a configured connection. An Administrator holds every
 *   staff permission there is, so this cannot be a permission.
 * - **The run row exists before the job does** (ADR 0032). An import is exactly
 *   the operation somebody starts and then goes to lunch, and one that never
 *   reached a worker is the failure nobody sees.
 */
beforeEach(function (): void {
    $this->withoutVite();

    $this->seed(ProviderOrganizationSeeder::class);
    app(SyncPermissions::class)->handle(app(PermissionRegistry::class));
    $this->seed(SystemRoleSeeder::class);

    $this->owner = StaffUser::factory()->create();
    $this->owner->assignRole(SystemRole::SuperAdmin);
    $this->owner = $this->owner->fresh();

    /*
     * Starting an import asks for a recent password (Phase 17, §20): it writes
     * customers, invoices and ledger rows straight into the database. These
     * tests are about what the endpoint validates, so the confirmation is
     * granted here — the guard itself is `SecurityHardeningTest`'s subject.
     */
    $this->withSession([RequireRecentAuthentication::SESSION_KEY => time()]);

    $this->administrator = StaffUser::factory()->create();
    $this->administrator->assignRole(SystemRole::Administrator);
    $this->administrator = $this->administrator->fresh();
});

it('offers the owner the eight domains in dependency order', function (): void {
    $this->actingAs($this->owner, 'staff')
        ->get('/admin/import')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->component('Admin/Import/Index')
            ->has('domains', 8)
            // Customers first, because everything else hangs off one.
            ->where('domains.0.value', ImportDomain::Customers->value)
            ->where('domains.7.value', ImportDomain::Tickets->value));
});

/**
 * There is no `legacy` connection on a test installation, so the source reports
 * the problem rather than the screen breaking. That is the case every operator
 * sees first.
 */
it('says what is wrong with the connection instead of failing', function (): void {
    $this->actingAs($this->owner, 'staff')
        ->get('/admin/import')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->where('sources.0.key', 'whmcs')
            ->where('sources.0.configured', false)
            // A sentence an operator can act on, and the counts not attempted.
            ->has('sources.0.problems', 1)
            ->where('sources.0.counts', null));
});

it('is shut to an administrator who is not the owner', function (): void {
    expect($this->administrator->effectivePermissions())->toContain('settings.manage');

    $this->actingAs($this->administrator, 'staff');

    $this->get('/admin/import')->assertForbidden();
    $this->post('/admin/import', [
        'source' => 'whmcs',
        'mode' => ImportMode::DryRun->value,
        'domains' => [ImportDomain::Customers->value],
    ])->assertForbidden();
});

/**
 * Refused before a run row exists. A failed run with nothing in it would be a
 * row an operator has to interpret, and the problems are already sentences.
 */
it('refuses to start when the source is not ready, and writes no run', function (): void {
    Queue::fake();

    $this->actingAs($this->owner, 'staff')
        ->from('/admin/import')
        ->post('/admin/import', [
            'source' => 'whmcs',
            'mode' => ImportMode::DryRun->value,
            'domains' => [ImportDomain::Customers->value],
        ])
        ->assertSessionHasErrors('domains');

    expect(ImportRun::query()->count())->toBe(0);
    Queue::assertNothingPushed();
});

it('refuses a source this installation does not have', function (): void {
    $this->actingAs($this->owner, 'staff')
        ->from('/admin/import')
        ->post('/admin/import', [
            'source' => 'plesk',
            'mode' => ImportMode::DryRun->value,
            'domains' => [ImportDomain::Customers->value],
        ])
        ->assertSessionHasErrors('domains');
});

it('refuses a domain nobody declared', function (): void {
    $this->actingAs($this->owner, 'staff')
        ->from('/admin/import')
        ->post('/admin/import', [
            'source' => 'whmcs',
            'mode' => ImportMode::DryRun->value,
            'domains' => ['everything'],
        ])
        ->assertSessionHasErrors('domains.0');
});

/**
 * The failures are the report. An operator needs each row by name with a reason,
 * because a report that showed only totals would tell them how many customers
 * they lost and nothing about which.
 */
it('shows the failures by name on the report', function (): void {
    // The boundary an operator's session would have set. The factory has no
    // organization of its own and `BelongsToOrganization` refuses to guess.
    app(OrganizationContext::class)->set($this->owner->organization_id);

    $run = ImportRun::factory()->create([
        'status' => ImportStatus::Completed->value,
        'domains' => [ImportDomain::Customers->value],
        'expected' => ['customers' => 3],
        'totals' => ['customers' => ['created' => 2, 'skipped' => 0, 'failed' => 1]],
    ]);

    ImportItem::factory()->create([
        'run_id' => $run->id,
        'domain' => ImportDomain::Customers->value,
        'external_id' => '4182',
        'outcome' => ImportOutcome::Failed->value,
        'label' => 'Analytical Engines Ltd',
        'message' => 'The currency [XYZ] is not one this platform knows.',
    ]);

    // A success, which must not appear in the failures list.
    ImportItem::factory()->create([
        'run_id' => $run->id,
        'domain' => ImportDomain::Customers->value,
        'outcome' => ImportOutcome::Created->value,
    ]);

    $this->actingAs($this->owner, 'staff')
        ->get("/admin/import/{$run->id}")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->component('Admin/Import/Show')
            // The denominator, so "2 of 3" is readable.
            ->where('run.expected.customers', 3)
            ->where('run.failed', 1)
            ->has('failures', 1)
            ->where('failures.0.externalId', '4182')
            ->where('failures.0.label', 'Analytical Engines Ltd')
            ->where('failures.0.message', 'The currency [XYZ] is not one this platform knows.'));
});

it('answers 404 for a run that is not there', function (): void {
    $this->actingAs($this->owner, 'staff')
        ->get('/admin/import/01jzzzzzzzzzzzzzzzzzzzzzzz')
        ->assertNotFound();
});

/**
 * The job goes on its own queue, and that queue has to be in the Horizon
 * supervisor or the jobs sit in Redis and the failure is silent.
 */
it('queues the import on the imports queue', function (): void {
    $supervisors = config('horizon.defaults');

    $queues = [];

    foreach ($supervisors as $supervisor) {
        foreach ($supervisor['queue'] ?? [] as $queue) {
            $queues[] = $queue;
        }
    }

    expect($queues)->toContain('imports');
    expect((new RunImportJob('run', 'org'))->queue)->toBe('imports');
});
