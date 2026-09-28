<?php

declare(strict_types=1);

use App\Application\Access\SyncPermissions;
use App\Application\Automation\TaskRegistry;
use App\Application\Intelligence\ReconcileServices;
use App\Domain\Access\PermissionRegistry;
use App\Domain\Access\SystemRole;
use App\Domain\Automation\AutomationTask;
use App\Domain\Automation\RunSummary;
use App\Domain\Intelligence\ReconciliationClass;
use App\Domain\Organizations\OrganizationType;
use App\Domain\Provisioning\ConnectionResult;
use App\Domain\Provisioning\Contracts\ProvisioningModule;
use App\Domain\Provisioning\ModuleCapabilities;
use App\Domain\Provisioning\ProvisioningResult;
use App\Domain\Provisioning\ServiceStatus;
use App\Domain\Provisioning\SyncResult;
use App\Infrastructure\Identity\Models\StaffUser;
use App\Infrastructure\Intelligence\Models\ReconciliationDismissal;
use App\Infrastructure\Intelligence\Models\ReconciliationFinding;
use App\Infrastructure\Organizations\Models\Organization;
use App\Infrastructure\Provisioning\Models\Server;
use App\Infrastructure\Provisioning\Models\Service;
use App\Infrastructure\Provisioning\ModuleRegistry;
use App\Support\Organizations\OrganizationContext;
use Carbon\CarbonImmutable;
use Database\Seeders\ProviderOrganizationSeeder;
use Database\Seeders\SystemRoleSeeder;

/**
 * Reconciliation (§22).
 *
 * **It reports and never repairs**, which is ADR 0031 and ADR 0032 applied to
 * a comparison: a sweep that put right what it found would suspend a customer
 * because a panel was slow to answer, and the audit row afterwards would say
 * the platform had done it to itself.
 *
 * The distinction this file exists to protect is `unknown` against `drift`. A
 * machine that might have been resized and a machine nobody could ask are
 * different things to act on, and folding them together would put a
 * provider's outage in front of an operator as four hundred customers whose
 * accounts had apparently changed.
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
 * What the fake panel currently says about every account on it.
 *
 * A static rather than a second registration: replacing the registry binding
 * twice would leave whichever was registered last, which is the same trap
 * `Http::fake()` called twice sets.
 */
final class PanelBelief
{
    public static ?ServiceStatus $status = ServiceStatus::Active;

    public static bool $reachable = true;

    public static string $message = 'The panel could not be reached.';
}

function panelSays(?ServiceStatus $status, bool $reachable = true): void
{
    PanelBelief::$status = $status;
    PanelBelief::$reachable = $reachable;
}

function registerFakePanel(): void
{
    $module = new class implements ProvisioningModule
    {
        public function key(): string
        {
            return 'fake-panel';
        }

        public function capabilities(): ModuleCapabilities
        {
            return new ModuleCapabilities(
                suspend: true,
                unsuspend: true,
                terminate: true,
                sync: true,
                testConnection: true,
            );
        }

        public function testConnection($server): ConnectionResult
        {
            return new ConnectionResult(true);
        }

        public function create($request): ProvisioningResult
        {
            return ProvisioningResult::succeeded();
        }

        public function suspend($service, ?string $reason = null): ProvisioningResult
        {
            return ProvisioningResult::succeeded();
        }

        public function unsuspend($service): ProvisioningResult
        {
            return ProvisioningResult::succeeded();
        }

        public function terminate($service): ProvisioningResult
        {
            return ProvisioningResult::succeeded();
        }

        public function changePackage($service, $change): ProvisioningResult
        {
            return ProvisioningResult::succeeded();
        }

        public function sync($service): SyncResult
        {
            return PanelBelief::$reachable
                ? new SyncResult(true, PanelBelief::$status)
                : SyncResult::unreachable(PanelBelief::$message);
        }
    };

    app(ModuleRegistry::class)->register($module);
}

function aReconciledService(Organization $provider, ServiceStatus $status): Service
{
    $server = Server::factory()->create([
        'organization_id' => $provider->id,
        'name' => 'web-7',
    ]);

    return Service::factory()->create([
        'organization_id' => $provider->id,
        'server_id' => $server->id,
        'name' => 'acme-hosting',
        'module' => 'fake-panel',
        'external_id' => 'acct-1',
        'status' => $status,
    ]);
}

function reconcile(): RunSummary
{
    return app(TaskRegistry::class)->resolve(AutomationTask::Reconcile)->handle();
}

it('says nothing at all when the provider agrees', function (): void {
    registerFakePanel();
    aReconciledService($this->provider, ServiceStatus::Active);
    panelSays(ServiceStatus::Active);

    reconcile();

    // Agreement is the absence of a row, which is what makes the queue
    // something an operator can finish reading.
    expect(ReconciliationFinding::query()->count())->toBe(0);
});

it('calls an account the provider suspended drift', function (): void {
    registerFakePanel();
    aReconciledService($this->provider, ServiceStatus::Active);
    panelSays(ServiceStatus::Suspended);

    reconcile();

    $finding = ReconciliationFinding::query()->sole();

    expect($finding->class)->toBe(ReconciliationClass::Drift)
        ->and($finding->subject_label)->toContain('web-7')
        // The words each side used, not this platform's vocabulary for them.
        ->and($finding->expected)->not->toBeNull()
        ->and($finding->found)->not->toBeNull();
});

/** The one that matters most: somebody is paying for something that is not there. */
it('calls an account that has gone missing', function (): void {
    registerFakePanel();
    aReconciledService($this->provider, ServiceStatus::Active);
    panelSays(ServiceStatus::Terminated);

    reconcile();

    expect(ReconciliationFinding::query()->sole()->class)->toBe(ReconciliationClass::Missing);
});

/** §22's own second example: platform terminated, the account still exists. */
it('calls an account we have finished with an orphan', function (): void {
    registerFakePanel();
    aReconciledService($this->provider, ServiceStatus::Terminated);
    panelSays(ServiceStatus::Active);

    reconcile();

    expect(ReconciliationFinding::query()->sole()->class)->toBe(ReconciliationClass::Orphan);
});

/**
 * The distinction the whole engine turns on. A provider that did not answer
 * has told us nothing about the account, and reading it as drift would put a
 * panel's outage in front of an operator as four hundred changed customers.
 */
it('calls an unreachable provider unknown, never drift', function (): void {
    registerFakePanel();
    aReconciledService($this->provider, ServiceStatus::Active);
    panelSays(null, reachable: false);

    reconcile();

    $finding = ReconciliationFinding::query()->sole();

    expect($finding->class)->toBe(ReconciliationClass::Unknown)
        // And the provider's own sentence, because "nobody could ask" is
        // useless without saying why.
        ->and($finding->found)->toContain('could not be reached');
});

/**
 * `ManualModule` is reachable and has no opinion. Reading its silence as
 * agreement would mark every manually-provisioned service healthy for ever.
 */
it('calls an adapter with no opinion unknown as well', function (): void {
    registerFakePanel();
    aReconciledService($this->provider, ServiceStatus::Active);
    panelSays(null);

    reconcile();

    expect(ReconciliationFinding::query()->sole()->class)->toBe(ReconciliationClass::Unknown);
});

it('keeps one finding while it stays true', function (): void {
    registerFakePanel();
    aReconciledService($this->provider, ServiceStatus::Active);
    panelSays(ServiceStatus::Suspended);

    reconcile();
    reconcile();

    expect(ReconciliationFinding::query()->count())->toBe(1);
});

/** Clearing is the half people forget, and a stale queue is one nobody reads. */
it('clears a finding when somebody puts it right', function (): void {
    registerFakePanel();
    aReconciledService($this->provider, ServiceStatus::Active);
    panelSays(ServiceStatus::Suspended);

    reconcile();

    panelSays(ServiceStatus::Active);
    reconcile();

    $finding = ReconciliationFinding::query()->sole();

    // Cleared, never deleted: the row is what says how long it was wrong.
    expect($finding->cleared_at)->not->toBeNull()
        ->and($finding->cleared_token)->toBe($finding->id);
});

/**
 * A drift that became a missing account is the same finding getting worse.
 * Closing and reopening it would reset the clock that says how long it has
 * been wrong.
 */
it('worsens a finding in place rather than opening a second one', function (): void {
    registerFakePanel();
    aReconciledService($this->provider, ServiceStatus::Active);
    panelSays(ServiceStatus::Suspended);

    reconcile();
    $first = ReconciliationFinding::query()->sole();

    panelSays(ServiceStatus::Terminated);
    reconcile();

    $finding = ReconciliationFinding::query()->sole();

    expect($finding->id)->toBe($first->id)
        ->and($finding->class)->toBe(ReconciliationClass::Missing)
        ->and($finding->first_seen_at->toDateTimeString())
        ->toBe($first->first_seen_at->toDateTimeString());
});

it('never repairs anything it finds', function (): void {
    registerFakePanel();
    $service = aReconciledService($this->provider, ServiceStatus::Active);
    panelSays(ServiceStatus::Suspended);

    reconcile();

    // A sweep that put this right would suspend a customer because a panel
    // was slow to answer.
    expect($service->fresh()?->status)->toBe(ServiceStatus::Active);
});

it('drives the screen, worst first', function (): void {
    registerFakePanel();
    aReconciledService($this->provider, ServiceStatus::Active);
    panelSays(ServiceStatus::Terminated);
    reconcile();

    ReconciliationFinding::factory()->of(ReconciliationClass::Unknown)->create([
        'organization_id' => $this->provider->id,
        'subject_label' => 'somewhere else',
        'remote_key' => 'acct-2',
        'source' => ReconcileServices::Resource,
    ]);

    $this->actingAs($this->admin, 'staff')
        ->get('/admin/intelligence/reconciliation')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Admin/Intelligence/Reconciliation')
            ->has('findings.data', 2)
            // Missing before unknown: somebody is paying for nothing, and a
            // provider that did not answer has said nothing about anybody.
            ->where('findings.data.0.class', 'missing')
            ->where('findings.data.1.class', 'unknown')
            // Two fields, always.
            ->where('findings.data.0.classTone', 'critical'));
});

it('lets support read the queue and not decide about it', function (): void {
    $agent = StaffUser::factory()->create();
    $agent->assignRole(SystemRole::Support);

    $this->actingAs($agent->fresh(), 'staff')
        ->get('/admin/intelligence/reconciliation')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('can.remediate', false));
});

it('refuses the queue to somebody with neither permission', function (): void {
    $nobody = StaffUser::factory()->create();

    $this->actingAs($nobody->fresh(), 'staff')
        ->get('/admin/intelligence/reconciliation')
        ->assertForbidden();
});

/**
 * A dismissal survives the sweep that cleared the finding, which is the whole
 * reason it is keyed the way a finding is keyed rather than by a finding's id.
 */
it('stops raising a difference somebody said was deliberate', function (): void {
    registerFakePanel();
    aReconciledService($this->provider, ServiceStatus::Terminated);
    panelSays(ServiceStatus::Active);

    reconcile();
    $finding = ReconciliationFinding::query()->sole();

    $this->actingAs($this->admin, 'staff')
        ->post("/admin/intelligence/reconciliation/{$finding->id}/dismiss", [
            'reason' => 'Kept for the migration, remove in March.',
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    reconcile();

    expect(ReconciliationFinding::query()->open()->count())->toBe(0)
        ->and(ReconciliationDismissal::query()->count())->toBe(1);
});

/**
 * ADR 0031 applied to somebody's judgement: the finding comes back by itself,
 * and no scheduled task has to run for the queue to be honest.
 */
it('raises it again once the dismissal window has passed', function (): void {
    registerFakePanel();
    aReconciledService($this->provider, ServiceStatus::Terminated);
    panelSays(ServiceStatus::Active);

    reconcile();
    $finding = ReconciliationFinding::query()->sole();

    $this->actingAs($this->admin, 'staff')
        ->post("/admin/intelligence/reconciliation/{$finding->id}/dismiss", [
            'reason' => 'Until the migration finishes.',
            'until' => CarbonImmutable::now()->addDay()->toDateTimeString(),
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    reconcile();
    expect(ReconciliationFinding::query()->open()->count())->toBe(0);

    CarbonImmutable::setTestNow(CarbonImmutable::now()->addDays(2));
    reconcile();

    expect(ReconciliationFinding::query()->open()->count())->toBe(1);
});

it('refuses a dismissal with nothing written on it', function (): void {
    registerFakePanel();
    aReconciledService($this->provider, ServiceStatus::Active);
    panelSays(ServiceStatus::Suspended);
    reconcile();

    $finding = ReconciliationFinding::query()->sole();

    $this->actingAs($this->admin, 'staff')
        ->post("/admin/intelligence/reconciliation/{$finding->id}/dismiss", ['reason' => ''])
        ->assertSessionHasErrors('reason');
});

it('refuses a dismissal to support', function (): void {
    registerFakePanel();
    aReconciledService($this->provider, ServiceStatus::Active);
    panelSays(ServiceStatus::Suspended);
    reconcile();

    $finding = ReconciliationFinding::query()->sole();
    $agent = StaffUser::factory()->create();
    $agent->assignRole(SystemRole::Support);

    $this->actingAs($agent->fresh(), 'staff')
        ->post("/admin/intelligence/reconciliation/{$finding->id}/dismiss", [
            'reason' => 'Mine now',
        ])
        ->assertForbidden();
});

/**
 * Never a translated sentence in a column.
 *
 * A word stored in the language of whichever scheduler run wrote it is a word
 * the next operator cannot read — the rule `health_message` has lived under
 * since the graph was built, and this screen broke it on the first pass: an
 * hourly sweep would have frozen "Active" and "Suspended" into English on a
 * Turkish installation. The row holds values; the screen words them.
 */
it('stores values and words them in the reader’s own language', function (): void {
    registerFakePanel();
    aReconciledService($this->provider, ServiceStatus::Active);
    panelSays(ServiceStatus::Suspended);

    reconcile();

    $finding = ReconciliationFinding::query()->sole();

    expect($finding->expected)->toBe('active')
        ->and($finding->found)->toBe('suspended');

    $this->admin->forceFill(['locale' => 'tr'])->save();

    $this->actingAs($this->admin->fresh(), 'staff')
        ->get('/admin/intelligence/reconciliation')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('findings.data.0.expected', (string) __('provisioning.statuses.active', [], 'tr'))
            ->where('findings.data.0.found', (string) __('provisioning.statuses.suspended', [], 'tr')));
});

/**
 * And a provider's own message is evidence, not vocabulary: translating it
 * would be this platform putting words into somebody else's mouth.
 */
it('leaves a provider’s own sentence exactly as it said it', function (): void {
    registerFakePanel();
    aReconciledService($this->provider, ServiceStatus::Active);
    panelSays(null, reachable: false);

    reconcile();

    $this->actingAs($this->admin, 'staff')
        ->get('/admin/intelligence/reconciliation')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('findings.data.0.found', PanelBelief::$message));
});
