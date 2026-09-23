<?php

declare(strict_types=1);

use App\Application\Access\SyncPermissions;
use App\Domain\Access\PermissionRegistry;
use App\Domain\Access\SystemRole;
use App\Domain\Operations\OperationState;
use App\Http\Middleware\HandleInertiaRequests;
use App\Infrastructure\Crm\Models\Customer;
use App\Infrastructure\Identity\Models\Contact;
use App\Infrastructure\Identity\Models\StaffUser;
use App\Infrastructure\Operations\Models\Operation;
use Database\Seeders\ProviderOrganizationSeeder;
use Database\Seeders\SystemRoleSeeder;
use Illuminate\Http\Request;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;

/**
 * The background operations drawer's props (§8, ADR 0032).
 *
 * An operation is visible before it finishes, and the drawer is how somebody
 * who was *not* looking finds out. Two things decide whether that works and
 * both are asserted here:
 *
 * - the counts are shared on every admin render, because a badge that is
 *   always grey is worse than no badge;
 * - they are `null` for anybody who may not see operations. Customer portal
 *   pages are shared the same props, and a count of the platform's failed
 *   provisioning runs is not theirs.
 *
 * The rows themselves are `Inertia::optional`: absent until the drawer asks.
 */
beforeEach(function (): void {
    $this->withoutVite();

    $this->seed(ProviderOrganizationSeeder::class);
    app(SyncPermissions::class)->handle(app(PermissionRegistry::class));
    $this->seed(SystemRoleSeeder::class);

    $this->admin = StaffUser::factory()->create();
    $this->admin->assignRole(SystemRole::Administrator);
    $this->admin = $this->admin->fresh();
});

/** A partial reload, the way the drawer makes one. */
function askForQueue(): TestResponse
{
    return test()->get('/admin', [
        'X-Inertia' => 'true',
        'X-Inertia-Version' => (string) app(HandleInertiaRequests::class)
            ->version(Request::create('/admin')),
        'X-Inertia-Partial-Component' => 'Admin/Dashboard',
        'X-Inertia-Partial-Data' => 'operationQueue,operations',
    ]);
}

it('counts what is running and what needs a person', function (): void {
    Operation::factory()->create(['state' => OperationState::Running->value]);
    Operation::factory()->create(['state' => OperationState::Pending->value]);
    Operation::factory()->create([
        'state' => OperationState::Failed->value,
        'resolved_at' => null,
    ]);
    // Resolved, so somebody already looked at it. Still failed, no longer a
    // thing the chrome should be shouting about.
    Operation::factory()->create([
        'state' => OperationState::Failed->value,
        'resolved_at' => now(),
    ]);

    $this->actingAs($this->admin, 'staff')
        ->get('/admin')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->where('operations.active', 2)
            ->where('operations.attention', 1));
});

it('does not build the rows until the drawer asks for them', function (): void {
    Operation::factory()->create(['state' => OperationState::Running->value]);

    $this->actingAs($this->admin, 'staff')
        ->get('/admin')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            // The counts, yes. Twelve rows with their errors and correlation
            // ids on every page load, no.
            ->has('operations')
            ->missing('operationQueue'));
});

it('hands the drawer the rows, unfinished ones first', function (): void {
    Operation::factory()->create([
        'state' => OperationState::Completed->value,
        'finished_at' => now()->subMinute(),
    ]);
    Operation::factory()->create([
        'state' => OperationState::Failed->value,
        'finished_at' => null,
        'correlation_id' => 'corr-1234',
    ]);

    $this->actingAs($this->admin, 'staff');

    askForQueue()
        ->assertOk()
        // The drawer is asked "what is going on", not "what happened", so a
        // run still in flight comes before one that finished a minute ago
        // even though the finished one is newer.
        ->assertJsonPath('props.operationQueue.0.state', OperationState::Failed->value)
        // §8 names the correlation ID on this drawer: it is the one string
        // that ties a failure here to the lines in the log.
        ->assertJsonPath('props.operationQueue.0.correlationId', 'corr-1234')
        ->assertJsonPath('props.operationQueue.1.state', OperationState::Completed->value);
});

/**
 * The portal shares these props too. A customer being told how many of the
 * platform's provisioning runs are on fire is a disclosure, not a feature.
 */
it('tells a customer nothing about the platform queue', function (): void {
    Operation::factory()->create(['state' => OperationState::Failed->value]);

    $customer = Customer::factory()->create();
    $owner = Contact::factory()->forCustomer($customer)->primary()->create();
    $owner->assignRole(SystemRole::AccountOwner);

    $this->actingAs($owner->fresh(), 'client')
        ->get('/client')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            // Null, not zero: zero is a claim about the queue, and null is
            // "this is not yours to know".
            ->where('operations', null));
});

/**
 * Asserted against the middleware rather than through a page, because every
 * admin screen authorizes something and a staff account holding nothing
 * cannot open one. Every shipped staff role happens to carry
 * `operations.view`, so a test built on a role would be a statement about
 * the seeder rather than about the gate.
 */
it('tells staff without the permission nothing either', function (): void {
    Operation::factory()->create(['state' => OperationState::Failed->value]);

    $newcomer = StaffUser::factory()->create();

    expect($newcomer->effectivePermissions())->not->toContain('operations.view');

    $this->actingAs($newcomer, 'staff');

    $shared = app(HandleInertiaRequests::class)->share(Request::create('/admin'));
    $counts = $shared['operations'];

    expect($counts)->toBeCallable();
    expect($counts())->toBeNull();
});
