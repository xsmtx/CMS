<?php

declare(strict_types=1);

use App\Application\Access\SyncPermissions;
use App\Domain\Access\PermissionRegistry;
use App\Domain\Access\SystemRole;
use App\Domain\Modules\ModuleState;
use App\Http\Middleware\RequireRecentAuthentication;
use App\Infrastructure\Identity\Models\StaffUser;
use App\Infrastructure\Modules\ActiveModules;
use App\Infrastructure\Modules\Models\ModuleRecord;
use App\Infrastructure\Modules\ModuleCatalogue;
use Database\Seeders\ProviderOrganizationSeeder;
use Database\Seeders\SystemRoleSeeder;

/**
 * The Modules screen, with every button pressed.
 *
 * `ModuleLifecycleTest` proves the rules and drives the use cases;
 * `ExampleModuleTest` drives the package. Neither pressed the buttons, which
 * is how the fleet screen next door spent several phases posting to routes
 * that had moved — the screens rendered, the tests passed, and every action
 * answered 404.
 *
 * So this file goes through the HTTP endpoints the page actually calls, on the
 * real `status-board` package, in the order an operator meets them.
 */
beforeEach(function (): void {
    $this->withoutVite();

    $this->seed(ProviderOrganizationSeeder::class);
    app(SyncPermissions::class)->handle(app(PermissionRegistry::class));
    $this->seed(SystemRoleSeeder::class);

    $this->owner = StaffUser::factory()->create();
    $this->owner->assignRole(SystemRole::SuperAdmin);
    $this->owner = $this->owner->fresh();

    $this->administrator = StaffUser::factory()->create();
    $this->administrator->assignRole(SystemRole::Administrator);
    $this->administrator = $this->administrator->fresh();

    app(ModuleCatalogue::class)->forget();
    app(ActiveModules::class)->forget();

    // Uninstalling runs the package's own migrations down, so it asks for a
    // password again. The guard is `SecurityHardeningTest`'s subject; here it
    // is a precondition.
    $this->withSession([RequireRecentAuthentication::SESSION_KEY => time()]);
});

it('installs, configures, enables, disables and uninstalls from the screen', function (): void {
    $this->actingAs($this->owner, 'staff')
        ->post('/admin/apps/modules', ['slug' => 'status-board'])
        ->assertRedirect();

    expect(ModuleRecord::query()->where('slug', 'status-board')->first()?->state)
        ->toBe(ModuleState::Installed);

    // Installing runs nothing, so a module with a required setting cannot be
    // enabled until the form has been filled in (ADR 0038).
    $this->actingAs($this->owner, 'staff')
        ->put('/admin/apps/modules/status-board/config', [
            'config' => ['url' => 'https://example.test/status', 'label' => 'Upstream', 'timeout' => 3],
        ])
        ->assertRedirect();

    $this->actingAs($this->owner, 'staff')
        ->post('/admin/apps/modules/status-board/enable')
        ->assertRedirect();

    expect(ModuleRecord::query()->where('slug', 'status-board')->first()?->state)
        ->toBe(ModuleState::Enabled);

    $this->actingAs($this->owner, 'staff')
        ->post('/admin/apps/modules/status-board/disable')
        ->assertRedirect();

    // `disabled`, not back to `installed`: "never run here" and "switched off"
    // are different answers, and an operator reading the screen needs both.
    expect(ModuleRecord::query()->where('slug', 'status-board')->first()?->state)
        ->toBe(ModuleState::Disabled);

    $this->actingAs($this->owner, 'staff')
        ->delete('/admin/apps/modules/status-board')
        ->assertRedirect();

    expect(ModuleRecord::query()->where('slug', 'status-board')->exists())->toBeFalse();
});

/**
 * Upgrade, which is the platform catching up with what is on disk.
 *
 * The version here is the same as the version recorded, so this is the ordinary
 * no-op an operator presses after replacing a package — and the thing it must
 * not do is refuse. A downgrade is refused, and that is asserted next to it so
 * the two answers cannot be confused.
 */
it('takes the version on disk, and refuses one that moves backwards', function (): void {
    $this->actingAs($this->owner, 'staff')
        ->post('/admin/apps/modules', ['slug' => 'status-board'])
        ->assertRedirect();

    $this->actingAs($this->owner, 'staff')
        ->post('/admin/apps/modules/status-board/upgrade')
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $record = ModuleRecord::query()->where('slug', 'status-board')->firstOrFail();

    expect($record->version)->toBe('1.0.0');

    // Pretend the row came from a newer package than the one on disk.
    $record->forceFill(['version' => '9.0.0'])->save();

    // A refusal is a message on the screen, not a 500 page. Until the buttons
    // were driven, nothing here caught `InvalidModule` and every refusal on
    // this screen — an unconfigured module, one built against another SDK, this
    // — ended in a server error instead of the sentence it already carries.
    $this->actingAs($this->owner, 'staff')
        ->post('/admin/apps/modules/status-board/upgrade')
        ->assertRedirect()
        ->assertSessionHasErrors('module');

    expect($record->fresh()->version)->toBe('9.0.0');
});

it('shows a refusal rather than a server error when a module cannot be enabled', function (): void {
    $this->actingAs($this->owner, 'staff')
        ->post('/admin/apps/modules', ['slug' => 'status-board'])
        ->assertRedirect();

    // `url` is required and has not been given, so enabling cannot run the
    // package (ADR 0038). The operator has to be told which field.
    $this->actingAs($this->owner, 'staff')
        ->post('/admin/apps/modules/status-board/enable')
        ->assertRedirect()
        ->assertSessionHasErrors('module');

    expect(ModuleRecord::query()->where('slug', 'status-board')->first()?->state)
        ->toBe(ModuleState::Failed);
});

it('refuses every button to an administrator who is not the owner', function (): void {
    $this->actingAs($this->administrator, 'staff')
        ->post('/admin/apps/modules', ['slug' => 'status-board'])
        ->assertForbidden();

    $this->actingAs($this->administrator, 'staff')
        ->get('/admin/apps/modules')
        ->assertForbidden();

    expect(ModuleRecord::query()->count())->toBe(0);
});

it('asks for a password again before uninstalling, and refuses a stranger first', function (): void {
    $this->actingAs($this->owner, 'staff')
        ->post('/admin/apps/modules', ['slug' => 'status-board'])
        ->assertRedirect();

    $this->flushSession();

    $this->actingAs($this->owner, 'staff')
        ->delete('/admin/apps/modules/status-board')
        ->assertRedirect('/admin/confirm-password');

    expect(ModuleRecord::query()->where('slug', 'status-board')->exists())->toBeTrue();

    // Refused before the challenge rather than after it: `owner` is on the
    // group and `auth.recent` is on the route.
    $this->actingAs($this->administrator, 'staff')
        ->delete('/admin/apps/modules/status-board')
        ->assertForbidden();
});
