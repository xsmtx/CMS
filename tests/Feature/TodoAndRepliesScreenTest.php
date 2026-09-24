<?php

declare(strict_types=1);

use App\Application\Access\SyncPermissions;
use App\Domain\Access\PermissionRegistry;
use App\Domain\Access\SystemRole;
use App\Domain\Organizations\OrganizationType;
use App\Domain\Platform\TodoStatus;
use App\Infrastructure\Identity\Models\StaffUser;
use App\Infrastructure\Organizations\Models\Organization;
use App\Infrastructure\Platform\Models\TodoItem;
use App\Infrastructure\Support\Models\CannedResponse;
use App\Infrastructure\Support\Models\Department;
use App\Support\Organizations\OrganizationContext;
use Database\Seeders\ProviderOrganizationSeeder;
use Database\Seeders\SystemRoleSeeder;

/**
 * Two small screens whose buttons nothing had ever pressed.
 *
 * Both are the shape that hides a bug well: a form beside a list, saved back to
 * the same page, so a refusal shows as the form simply not clearing. One of
 * them was broken — see the department case below.
 */
beforeEach(function (): void {
    $this->withoutVite();

    $this->seed(ProviderOrganizationSeeder::class);
    app(SyncPermissions::class)->handle(app(PermissionRegistry::class));
    $this->seed(SystemRoleSeeder::class);

    $this->provider = Organization::query()
        ->where('type', OrganizationType::Provider->value)
        ->firstOrFail();

    app(OrganizationContext::class)->set($this->provider->id);

    $this->staff = StaffUser::factory()->create(['organization_id' => $this->provider->id]);
    $this->staff->assignRole(SystemRole::Administrator);
    $this->staff = $this->staff->fresh();
});

it('writes, edits and clears a todo from the screen', function (): void {
    $this->actingAs($this->staff, 'staff')
        ->post('/admin/todo', [
            'title' => 'Chase the failed renewal',
            'body' => 'Card expired.',
            // The form sends empty strings for the two optional fields, which
            // is what a browser sends and not the same as omitting them.
            'due_on' => '',
            'assigned_to' => '',
            'status' => '',
        ])
        ->assertRedirect();

    $item = TodoItem::query()->where('title', 'Chase the failed renewal')->first();

    expect($item)->not->toBeNull()
        ->and($item->due_on)->toBeNull()
        ->and($item->assigned_to)->toBeNull()
        ->and($item->created_by)->toBe($this->staff->id);

    $this->actingAs($this->staff, 'staff')
        ->put('/admin/todo/'.$item->id, [
            'title' => 'Chase the failed renewal',
            'body' => 'Card expired.',
            'due_on' => '2026-10-15',
            'assigned_to' => $this->staff->id,
            'status' => TodoStatus::Done->value,
        ])
        ->assertRedirect();

    // Stamped when it is done, so "when was this finished" never describes
    // something unfinished.
    expect($item->fresh()->status)->toBe(TodoStatus::Done)
        ->and($item->fresh()->completed_at)->not->toBeNull()
        ->and($item->fresh()->assigned_to)->toBe($this->staff->id);

    $this->actingAs($this->staff, 'staff')
        ->put('/admin/todo/'.$item->id, [
            'title' => 'Chase the failed renewal',
            'status' => TodoStatus::Pending->value,
        ])
        ->assertRedirect();

    expect($item->fresh()->completed_at)->toBeNull();

    $this->actingAs($this->staff, 'staff')
        ->delete('/admin/todo/'.$item->id)
        ->assertRedirect();

    expect(TodoItem::query()->whereKey($item->id)->exists())->toBeFalse();
});

it('refuses a todo with no title, without writing one', function (): void {
    $this->actingAs($this->staff, 'staff')
        ->post('/admin/todo', ['title' => ''])
        ->assertSessionHasErrors('title');

    expect(TodoItem::query()->count())->toBe(0);
});

/**
 * The bug this file found.
 *
 * `department_id` was validated against `exists:departments,id`, and the
 * department model's table is `support_departments`. So choosing a department —
 * the whole point of scoping a reply to one queue — could never validate, and
 * only "every department" saved. The same mistake was found once before, on the
 * ticket screen, which is why it is worth a test of its own rather than a note.
 */
it('saves a predefined reply against a department', function (): void {
    $department = Department::factory()->create(['organization_id' => $this->provider->id]);

    $this->actingAs($this->staff, 'staff')
        ->post('/admin/support/replies', [
            'name' => 'Card declined',
            'body' => 'Your bank refused the payment. Please try another card.',
            'department_id' => $department->id,
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    $reply = CannedResponse::query()->where('name', 'Card declined')->first();

    expect($reply)->not->toBeNull()
        ->and($reply->department_id)->toBe($department->id);
});

it('saves a reply for every department, edits it and deletes it', function (): void {
    $this->actingAs($this->staff, 'staff')
        ->post('/admin/support/replies', [
            'name' => 'Welcome',
            'body' => 'Thanks for getting in touch.',
            'department_id' => '',
        ])
        ->assertRedirect();

    $reply = CannedResponse::query()->where('name', 'Welcome')->firstOrFail();

    expect($reply->department_id)->toBeNull();

    $this->actingAs($this->staff, 'staff')
        ->put('/admin/support/replies/'.$reply->id, [
            'name' => 'Welcome back',
            'body' => 'Thanks for getting in touch again.',
            'department_id' => '',
        ])
        ->assertRedirect();

    expect($reply->fresh()->name)->toBe('Welcome back');

    $this->actingAs($this->staff, 'staff')
        ->delete('/admin/support/replies/'.$reply->id)
        ->assertRedirect();

    expect(CannedResponse::query()->whereKey($reply->id)->exists())->toBeFalse();
});

it('refuses a reply to somebody who may only read tickets', function (): void {
    $reader = StaffUser::factory()->create(['organization_id' => $this->provider->id]);

    $this->actingAs($reader, 'staff')
        ->post('/admin/support/replies', ['name' => 'Nope', 'body' => 'Nope'])
        ->assertForbidden();

    expect(CannedResponse::query()->count())->toBe(0);
});
