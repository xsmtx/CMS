<?php

declare(strict_types=1);

use App\Application\Access\SyncPermissions;
use App\Domain\Access\PermissionRegistry;
use App\Domain\Access\SystemRole;
use App\Domain\Crm\CancellationStatus;
use App\Domain\Crm\CancellationType;
use App\Domain\Provisioning\ServiceStatus;
use App\Infrastructure\Crm\Models\CancellationRequest;
use App\Infrastructure\Identity\Models\StaffUser;
use App\Infrastructure\Provisioning\Models\Service;
use App\Infrastructure\Provisioning\Models\ServiceAddon;
use Database\Seeders\ProviderOrganizationSeeder;
use Database\Seeders\SystemRoleSeeder;
use Inertia\Testing\AssertableInertia;

/**
 * A cancellation is a request, not a status.
 *
 * The service already has `cancel_pending`, which says *that* it is going
 * away. It cannot say who asked, when, why, or whether they wanted it off
 * today — and those four facts are the substance of a cancellation queue.
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

it('shows what is outstanding and nothing else', function (): void {
    CancellationRequest::factory()->count(2)->create();
    CancellationRequest::factory()->create(['status' => CancellationStatus::Completed->value]);

    $this->actingAs($this->admin, 'staff')
        ->get('/admin/cancellations')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->component('Admin/Cancellations/Index')
            // A queue that opened on its own archive would hide the two
            // still waiting behind a year of completed ones.
            ->has('requests.data', 2));

    $this->actingAs($this->admin, 'staff')
        ->get('/admin/cancellations?status=completed')
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->has('requests.data', 1));
});

it('completes an end-of-term request by marking the service cancelling', function (): void {
    $service = Service::factory()->active()->create();
    $request = CancellationRequest::factory()->on($service)->create();

    $this->actingAs($this->admin, 'staff')
        ->post("/admin/cancellations/{$request->id}/complete")
        ->assertRedirect();

    expect($request->fresh()?->status)->toBe(CancellationStatus::Completed)
        // Not terminated: they have paid until the end of the term and
        // expect to keep what they bought until then.
        ->and($service->fresh()?->status)->toBe(ServiceStatus::CancelPending);
});

it('terminates immediately when that is what was asked', function (): void {
    $service = Service::factory()->active()->create();
    $addon = ServiceAddon::factory()->on($service)->active()->create();

    $request = CancellationRequest::factory()->on($service)->immediate()->create();

    $this->actingAs($this->admin, 'staff')
        ->post("/admin/cancellations/{$request->id}/complete")
        ->assertRedirect();

    expect($service->fresh()?->status)->toBe(ServiceStatus::Terminated)
        // Through `TransitionService`, so the addons went with it.
        ->and($addon->fresh()?->status->value)->toBe('terminated');
});

/**
 * Customers change their minds, and a queue that could only complete a
 * request would make an operator complete one that should not happen.
 */
it('puts a service back when the customer changes their mind', function (): void {
    $service = Service::factory()->active()->create();
    $request = CancellationRequest::factory()->on($service)->create();

    $this->actingAs($this->admin, 'staff')
        ->post("/admin/cancellations/{$request->id}/complete")
        ->assertRedirect();

    $this->actingAs($this->admin, 'staff')
        ->post("/admin/cancellations/{$request->id}/withdraw")
        ->assertRedirect();

    expect($request->fresh()?->status)->toBe(CancellationStatus::Withdrawn)
        ->and($service->fresh()?->status)->toBe(ServiceStatus::Active);
});

it('never un-terminates a service a withdrawal cannot bring back', function (): void {
    $service = Service::factory()->create(['status' => ServiceStatus::Terminated->value]);
    $request = CancellationRequest::factory()->on($service)->immediate()->create();

    $this->actingAs($this->admin, 'staff')
        ->post("/admin/cancellations/{$request->id}/withdraw")
        ->assertRedirect();

    // The account is gone at the provider. A withdrawal cannot un-delete
    // somebody's data, and pretending otherwise is worse than saying so.
    expect($service->fresh()?->status)->toBe(ServiceStatus::Terminated)
        ->and($request->fresh()?->status)->toBe(CancellationStatus::Withdrawn);
});

it('finds a request by the reason somebody gave', function (): void {
    CancellationRequest::factory()->create(['reason' => 'Too expensive for what we use.']);
    CancellationRequest::factory()->create(['reason' => 'Moving to another provider.']);

    $this->actingAs($this->admin, 'staff')
        ->get('/admin/cancellations?reason=expensive')
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->has('requests.data', 1));
});

it('filters by whether they wanted it off today', function (): void {
    CancellationRequest::factory()->immediate()->create();
    CancellationRequest::factory()->create();

    $this->actingAs($this->admin, 'staff')
        ->get('/admin/cancellations?type='.CancellationType::Immediate->value)
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->has('requests.data', 1));
});

/**
 * Strict mode only reports a lazy load when the query returned more than
 * one row, so the fixture has two of everything.
 */
it('opens the queue without lazily loading anything the row reads', function (): void {
    CancellationRequest::factory()->count(2)->create();

    $this->actingAs($this->admin, 'staff')
        ->get('/admin/cancellations')
        ->assertOk();
});
