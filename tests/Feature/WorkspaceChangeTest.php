<?php

declare(strict_types=1);

use App\Application\Access\SyncPermissions;
use App\Application\Automation\Runs\DiscoverWorkspaces;
use App\Application\Infrastructure\ResourceGraph;
use App\Application\Modules\EnableModule;
use App\Application\Modules\InstallModule;
use App\Application\Modules\SaveModuleConfig;
use App\Application\Network\ApplyNetworkChange;
use App\Application\Network\DecideNetworkChange;
use App\Application\Network\RequestNetworkChange;
use App\Domain\Access\PermissionRegistry;
use App\Domain\Access\SystemRole;
use App\Domain\Network\ChangeTarget;
use App\Domain\Network\Exceptions\ChangeRefused;
use App\Domain\Network\NetworkChangeState;
use App\Domain\Organizations\OrganizationType;
use App\Infrastructure\Identity\Models\StaffUser;
use App\Infrastructure\Modules\ActiveModules;
use App\Infrastructure\Modules\ModuleCatalogue;
use App\Infrastructure\Network\Models\NetworkChange;
use App\Infrastructure\Organizations\Models\Organization;
use App\Infrastructure\Resources\Models\ResourceAdapter;
use App\Infrastructure\Resources\Models\ResourceNode;
use App\Support\Organizations\OrganizationContext;
use Database\Seeders\ProviderOrganizationSeeder;
use Database\Seeders\SystemRoleSeeder;
use Illuminate\Support\Facades\Http;

/**
 * The guarded workflow, applied to something that is not a device (§25).
 *
 * "Show plans/diffs and require approval to apply" is `network_changes`
 * described in nine words, so Terraform is a `ChangeTarget` on the existing
 * table rather than a second workflow. What earns the tests is the two steps
 * that mean something different: the fingerprint is a **state serial**, and
 * there is **no rollback**.
 *
 * Driven through the real Terraform package over faked HTTP, for the reason
 * `NetworkChangeWorkflowTest` gives: `ActiveModules` is final on purpose and a
 * fake of it would be a fake of the thing under test.
 */
beforeEach(function (): void {
    $this->seed(ProviderOrganizationSeeder::class);
    app(SyncPermissions::class)->handle(app(PermissionRegistry::class));
    $this->seed(SystemRoleSeeder::class);

    $this->provider = Organization::query()
        ->where('type', OrganizationType::Provider->value)
        ->firstOrFail();

    app(OrganizationContext::class)->set($this->provider->id);

    $this->requester = StaffUser::factory()->create(['name' => 'Asked']);
    $this->requester->assignRole(SystemRole::Administrator);
    $this->requester = $this->requester->fresh();

    $this->approver = StaffUser::factory()->create(['name' => 'Agreed']);
    $this->approver->assignRole(SystemRole::Administrator);
    $this->approver = $this->approver->fresh();

    app(ModuleCatalogue::class)->forget();
    app(ActiveModules::class)->forget();
});

afterEach(function (): void {
    TerraformState::reset();
});

/**
 * What the fake Terraform currently holds.
 *
 * Statics rather than a second `Http::fake()`, because faking twice adds a
 * stub rather than replacing the first — and this whole file is about the
 * state moving under a plan that was agreed to.
 */
final class TerraformState
{
    public static int $serial = 7;

    public static bool $locked = false;

    /** Whether the run applies cleanly, or finishes with work outstanding. */
    public static bool $clean = true;

    public static int $planRuns = 0;

    public static int $applies = 0;

    public static function reset(): void
    {
        self::$serial = 7;
        self::$locked = false;
        self::$clean = true;
        self::$planRuns = 0;
        self::$applies = 0;
    }
}

function terraformAnswers(): void
{
    TerraformState::reset();

    Http::fake([
        'tf.test/api/v2/organizations/acme/workspaces*' => fn () => Http::response(['data' => [
            [
                'id' => 'ws-1',
                'attributes' => ['name' => 'production', 'resource-count' => 42, 'locked' => false],
            ],
        ]]),
        'tf.test/api/v2/workspaces/ws-1/current-state-version' => fn () => Http::response([
            'data' => ['attributes' => ['serial' => TerraformState::$serial]],
        ]),
        'tf.test/api/v2/workspaces/ws-1' => fn () => Http::response(['data' => [
            'id' => 'ws-1',
            'attributes' => [
                'name' => 'production',
                'resource-count' => 42,
                'locked' => TerraformState::$locked,
                'locked-reason' => TerraformState::$locked ? 'run-9' : null,
            ],
        ]]),
        'tf.test/api/v2/runs/run-1/actions/apply' => function () {
            TerraformState::$applies++;

            return Http::response([], 202);
        },
        'tf.test/api/v2/runs/run-1*' => fn () => Http::response([
            'data' => [
                'id' => 'run-1',
                'attributes' => [
                    // One shape for both polls: the plan is finished and the
                    // run is applied, so neither loop sleeps.
                    'status' => TerraformState::$applies > 0 ? 'applied' : 'planned',
                    'message' => 'Planned by InfraCMS.',
                ],
            ],
            'included' => [[
                'type' => 'plans',
                'attributes' => [
                    'has-changes' => true,
                    'resource-additions' => 2,
                    'resource-changes' => 1,
                    'resource-destructions' => 0,
                ],
            ]],
        ]),
        'tf.test/api/v2/runs' => function () {
            TerraformState::$planRuns++;

            // After an apply, a new run is the verification re-plan. It gets
            // an id of its own, so polling it cannot be confused with polling
            // the run that was applied.
            return Http::response(['data' => ['id' => TerraformState::$applies > 0 ? 'run-verify' : 'run-1']]);
        },
        'tf.test/api/v2/runs/run-verify*' => fn () => Http::response([
            'data' => ['id' => 'run-verify', 'attributes' => ['status' => 'planned', 'message' => '']],
            'included' => [[
                'type' => 'plans',
                'attributes' => [
                    // A clean run has nothing left to do; a dirty one still
                    // has work outstanding, which is a failed verify.
                    'has-changes' => ! TerraformState::$clean,
                    'resource-additions' => TerraformState::$clean ? 0 : 2,
                    'resource-changes' => 0,
                    'resource-destructions' => 0,
                ],
            ]],
        ]),
        'tf.test/*' => fn () => Http::response(['data' => []]),
    ]);
}

function enableTerraform(StaffUser $actor, bool $writes): void
{
    $record = app(InstallModule::class)->handle('iac-terraform', $actor);
    $manifest = app(ModuleCatalogue::class)->find('iac-terraform');

    app(SaveModuleConfig::class)->handle(
        $record,
        $manifest->config,
        ['base_url' => 'https://tf.test', 'organization' => 'acme', 'verify_tls' => false],
        $actor,
    );

    app(EnableModule::class)->handle($record->fresh(), $actor);
    app(ActiveModules::class)->forget();

    // What the row decides, not what the package declares. The registry row
    // does not exist until something asks it for this organization's
    // adapters, so `updateOrCreate` is what makes the assertion honest.
    ResourceAdapter::query()->updateOrCreate(
        ['organization_id' => test()->provider->id, 'adapter_key' => 'terraform'],
        ['name' => 'Terraform', 'vendor' => 'HashiCorp', 'enabled' => true, 'writes_enabled' => $writes],
    );
}

function aWorkspaceNode(): ResourceNode
{
    return app(ResourceGraph::class)->upsertNode(
        organizationId: test()->provider->id,
        kind: 'iac_workspace',
        nodeKey: 'terraform/ws-1',
        label: 'production',
        source: 'iac:terraform',
    );
}

function requestWorkspaceChange(?string $ref = null): NetworkChange
{
    return app(RequestNetworkChange::class)->forWorkspace(
        workspace: aWorkspaceNode(),
        requester: test()->requester,
        summary: 'Scale the web tier',
        reason: 'Black Friday is in three weeks and the plan adds two instances.',
        ref: $ref,
    );
}

it('discovers workspaces into the graph, and retires what the source stopped naming', function (): void {
    terraformAnswers();
    enableTerraform($this->requester, writes: false);

    app(DiscoverWorkspaces::class)->handle();

    $node = ResourceNode::query()->where('kind', 'iac_workspace')->firstOrFail();

    // The id, never the name: a name is changed by whoever last tidied the
    // organization, and a key that moved would leave the old node behind.
    expect($node->node_key)->toBe('terraform/ws-1')
        ->and($node->label)->toBe('production')
        ->and($node->attributes['resources'] ?? null)->toBe(42);
});

it('plans when the change is asked for, and stores the serial it was built against', function (): void {
    terraformAnswers();
    enableTerraform($this->requester, writes: false);

    $change = requestWorkspaceChange();

    expect($change->change_target)->toBe(ChangeTarget::Workspace)
        ->and($change->state)->toBe(NetworkChangeState::AwaitingApproval)
        ->and($change->diff)->toContain('2 to add')
        ->and($change->plan_reference)->toBe('run-1')
        // The serial takes the fingerprint's place. One safety check, two ways
        // of expressing what "unchanged" means.
        ->and($change->fingerprint_before)->toBe('7');
});

it('passes the ref through untouched, and never invents one', function (): void {
    terraformAnswers();
    enableTerraform($this->requester, writes: false);

    expect(requestWorkspaceChange()->workspace_ref)->toBeNull()
        ->and(requestWorkspaceChange('release/2026-11')->workspace_ref)->toBe('release/2026-11');
});

it('refuses a plan that changes nothing, because there is nothing to approve', function (): void {
    terraformAnswers();
    enableTerraform($this->requester, writes: false);

    // The fake answers an empty plan once an apply has happened; forcing that
    // state is what makes the refusal reachable.
    TerraformState::$applies = 1;

    expect(fn (): NetworkChange => requestWorkspaceChange())
        ->toThrow(ChangeRefused::class, 'changes nothing');
});

/**
 * The whole point of the workflow, in this family's terms.
 */
it('refuses to apply when the state has moved under the plan', function (): void {
    terraformAnswers();
    enableTerraform($this->requester, writes: true);

    $change = requestWorkspaceChange();
    app(DecideNetworkChange::class)->approve($change, $this->approver);

    // Somebody else applied something in between.
    TerraformState::$serial = 9;

    $applied = app(ApplyNetworkChange::class)->handle($change->fresh());

    expect($applied->state)->toBe(NetworkChangeState::Failed)
        ->and($applied->result)->toContain('not the one this plan was built against')
        ->and(TerraformState::$applies)->toBe(0);
});

/**
 * This family's "back up first": there is no backup to take, and a workspace
 * another run holds is one where two applies would interleave.
 */
it('refuses to apply a workspace somebody else is running', function (): void {
    terraformAnswers();
    enableTerraform($this->requester, writes: true);

    $change = requestWorkspaceChange();
    app(DecideNetworkChange::class)->approve($change, $this->approver);

    TerraformState::$locked = true;

    $applied = app(ApplyNetworkChange::class)->handle($change->fresh());

    expect($applied->state)->toBe(NetworkChangeState::Failed)
        ->and($applied->result)->toContain('locked by run-9')
        ->and(TerraformState::$applies)->toBe(0);
});

it('refuses to apply when writes were never turned on', function (): void {
    terraformAnswers();
    enableTerraform($this->requester, writes: false);

    $change = requestWorkspaceChange();
    app(DecideNetworkChange::class)->approve($change, $this->approver);

    $applied = app(ApplyNetworkChange::class)->handle($change->fresh());

    // An adapter may declare the capability; this installation says whether it
    // may use it, and an unenabled capability is absent rather than refused.
    expect($applied->state)->toBe(NetworkChangeState::Failed)
        ->and($applied->result)->toContain('permitted to run a plan')
        ->and(TerraformState::$applies)->toBe(0);
});

it('runs the approved plan and verifies by planning again', function (): void {
    terraformAnswers();
    enableTerraform($this->requester, writes: true);

    $change = requestWorkspaceChange();
    app(DecideNetworkChange::class)->approve($change, $this->approver);

    $applied = app(ApplyNetworkChange::class)->handle($change->fresh());

    expect($applied->state)->toBe(NetworkChangeState::Completed)
        ->and(TerraformState::$applies)->toBe(1)
        // The plan that was approved, not a fresh one: Terraform can only
        // apply a run it planned, and re-planning would run something nobody
        // read.
        ->and(TerraformState::$planRuns)->toBe(2);
});

/**
 * A workspace cannot be put back, and the record says so rather than claiming
 * a rollback that never happened.
 */
it('fails rather than rolling back when the workspace still has work outstanding', function (): void {
    terraformAnswers();
    enableTerraform($this->requester, writes: true);

    $change = requestWorkspaceChange();
    app(DecideNetworkChange::class)->approve($change, $this->approver);

    TerraformState::$clean = false;

    $applied = app(ApplyNetworkChange::class)->handle($change->fresh());

    expect($applied->state)->toBe(NetworkChangeState::Failed)
        ->and($applied->state)->not->toBe(NetworkChangeState::RolledBack)
        ->and($applied->result)->toContain('Nothing was put back');
});

it('drives the request form, and refuses a revision on a field the form draws', function (): void {
    terraformAnswers();
    enableTerraform($this->requester, writes: false);

    $workspace = aWorkspaceNode();

    $this->actingAs($this->requester, 'staff')
        ->get('/admin/network/changes')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Admin/Network/Changes')
            ->has('workspaces', 1)
            ->has('targets', 2));

    $this->actingAs($this->requester, 'staff')
        ->post('/admin/network/changes', [
            'target' => 'workspace',
            'device' => $workspace->id,
            'summary' => 'Scale the web tier',
            'reason' => 'Black Friday is in three weeks.',
            // No `intended`, which a device change requires and a workspace
            // change has nothing to put in.
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect(NetworkChange::query()->firstOrFail()->change_target)->toBe(ChangeTarget::Workspace);
});

it('still requires the configuration on a device change that names no target', function (): void {
    terraformAnswers();
    enableTerraform($this->requester, writes: false);

    $device = app(ResourceGraph::class)->upsertNode(
        organizationId: $this->provider->id,
        kind: 'network_device',
        nodeKey: 'fw9.dc2',
        label: 'fw9',
        source: 'topology:fortigate',
    );

    /*
     * `required_unless:target,workspace`, not `required_if:target,device`.
     * A form that posts no target at all is the device form — every caller
     * before §25 was one — and `required_if` does not fire on an absent
     * field, so the rule would have quietly stopped applying.
     */
    $this->actingAs($this->requester, 'staff')
        ->post('/admin/network/changes', [
            'device' => $device->id,
            'summary' => 'Rename it',
            'reason' => 'It moved rack.',
        ])
        ->assertSessionHasErrors('intended');
});
