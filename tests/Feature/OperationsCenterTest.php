<?php

declare(strict_types=1);

use App\Application\Access\SyncPermissions;
use App\Application\Automation\RecordedRun;
use App\Application\Automation\Runs\RetryFailedOperations;
use App\Application\Operations\Operations;
use App\Application\Operations\WatchedDispatch;
use App\Domain\Access\PermissionRegistry;
use App\Domain\Access\SystemRole;
use App\Domain\Automation\AutomationTask;
use App\Domain\Operations\OperationState;
use App\Domain\Operations\OperationType;
use App\Infrastructure\Identity\Models\StaffUser;
use App\Infrastructure\Operations\Models\Operation;
use App\Infrastructure\Provisioning\Jobs\ProvisionService;
use App\Infrastructure\Provisioning\Models\Service;
use Database\Seeders\ProviderOrganizationSeeder;
use Database\Seeders\SystemRoleSeeder;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia;

beforeEach(function (): void {
    $this->withoutVite();

    $this->seed(ProviderOrganizationSeeder::class);
    app(SyncPermissions::class)->handle(app(PermissionRegistry::class));
    $this->seed(SystemRoleSeeder::class);

    $this->operations = app(Operations::class);

    $this->admin = StaffUser::factory()->create();
    $this->admin->assignRole(SystemRole::Administrator);
    $this->admin = $this->admin->fresh();
});

it('opens the operation before the job is dispatched', function (): void {
    Queue::fake();

    $service = Service::factory()->create();

    app(WatchedDispatch::class)->handle(
        OperationType::ServiceProvision,
        $service,
        new ProvisionService($service->id),
    );

    $operation = Operation::query()->withoutGlobalScope('organization')->sole();

    // The whole argument for the table: a job that never reaches a worker
    // still leaves something an operator can see.
    expect($operation->state)->toBe(OperationState::Pending)
        ->and($operation->subject_id)->toBe($service->id);

    Queue::assertPushed(ProvisionService::class, fn (ProvisionService $job): bool => $job->operationId === $operation->id);
});

it('retries with a growing gap and gives up after the last attempt', function (): void {
    $operation = Operation::factory()->create(['max_attempts' => 2]);

    $this->operations->running($operation);
    $this->operations->failed($operation, 'the panel timed out');

    expect($operation->fresh()?->state)->toBe(OperationState::Retrying)
        ->and($operation->fresh()?->next_attempt_at)->not->toBeNull();

    $this->operations->running($operation->fresh());
    $again = $operation->fresh();
    $this->operations->failed($again, 'the panel timed out again');

    // Out of attempts: failed, not retrying forever.
    expect($again->fresh()?->state)->toBe(OperationState::Failed)
        ->and($again->fresh()?->next_attempt_at)->toBeNull();
});

it('redacts a provider secret out of the error before storing it', function (): void {
    $operation = Operation::factory()->create();

    $this->operations->failed($operation, 'auth failed for token=sk_live_abcdef123456');

    expect($operation->fresh()?->error)->not->toContain('sk_live_abcdef123456');
});

it('treats manual intervention as an end state, not a retry', function (): void {
    $operation = Operation::factory()->create();

    $this->operations->needsIntervention($operation, 'the losing registrar rejected it');

    $fresh = $operation->fresh();

    expect($fresh?->state)->toBe(OperationState::ManualIntervention)
        ->and($fresh?->needs_intervention)->toBeTrue()
        // Nothing automatic will pick it up again.
        ->and($fresh?->next_attempt_at)->toBeNull();
});

it('puts a due operation back in the queue', function (): void {
    Queue::fake();

    $service = Service::factory()->create();

    Operation::factory()->dueForRetry()->create([
        'organization_id' => $service->organization_id,
        'type' => OperationType::ServiceProvision->value,
        'subject_type' => Service::class,
        'subject_id' => $service->id,
    ]);

    $record = app(RecordedRun::class)->handle(
        AutomationTask::Retries,
        app(RetryFailedOperations::class),
    );

    expect($record->changed)->toBe(1);

    Queue::assertPushed(ProvisionService::class);
});

it('leaves an operation that needs a person where it is', function (): void {
    Queue::fake();

    Operation::factory()->needingIntervention()->create();

    $record = app(RecordedRun::class)->handle(
        AutomationTask::Retries,
        app(RetryFailedOperations::class),
    );

    expect($record->examined)->toBe(0);

    Queue::assertNothingPushed();
});

it('shows staff the operations that need attention first', function (): void {
    Operation::factory()->needingIntervention()->create();
    Operation::factory()->create(['state' => OperationState::Completed->value]);

    $this->actingAs($this->admin, 'staff')
        ->get('/admin/operations')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->component('Admin/Operations/Index')
            // The default view is the problem list, not the history.
            ->has('operations.data', 1)
            ->where('attention', 1));
});

it('lets staff retry a failed operation without resetting its attempt count', function (): void {
    $operation = Operation::factory()->create([
        'state' => OperationState::Failed->value,
        'attempt' => 3,
        'max_attempts' => 3,
    ]);

    $this->actingAs($this->admin, 'staff')
        ->post('/admin/operations/'.$operation->id.'/retry')
        ->assertRedirect();

    $fresh = $operation->fresh();

    // Somebody retrying a thing that failed three times should see it is
    // on its fourth.
    expect($fresh?->state)->toBe(OperationState::Retrying)
        ->and($fresh?->attempt)->toBe(3)
        ->and($fresh?->max_attempts)->toBe(4);
});

it('keeps the error after an operator marks it handled', function (): void {
    $operation = Operation::factory()->needingIntervention()->create();

    $this->actingAs($this->admin, 'staff')
        ->post('/admin/operations/'.$operation->id.'/resolve')
        ->assertRedirect();

    $fresh = $operation->fresh();

    expect($fresh?->resolved_at)->not->toBeNull()
        ->and($fresh?->needs_intervention)->toBeFalse()
        // An operation that went wrong and was fixed by hand is exactly
        // the history somebody wants next quarter.
        ->and($fresh?->error)->not->toBeNull();
});

it('refuses the operations screen to staff without the permission', function (): void {
    $this->actingAs(StaffUser::factory()->create(), 'staff')
        ->get('/admin/operations')
        ->assertForbidden();
});
