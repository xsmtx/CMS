<?php

declare(strict_types=1);

use App\Application\Access\SyncPermissions;
use App\Domain\Access\PermissionRegistry;
use App\Domain\Access\SystemRole;
use App\Infrastructure\Identity\Models\StaffUser;
use Database\Seeders\SystemRoleSeeder;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia;

beforeEach(function (): void {
    // The suite does not build front-end assets; asserting on the page
    // component is what matters, not on the bundle hash.
    $this->withoutVite();

    app(SyncPermissions::class)->handle(app(PermissionRegistry::class));

    $this->seed(SystemRoleSeeder::class);
});

it('redirects an anonymous visitor away from the admin area', function (): void {
    $this->get('/admin')->assertRedirect('/admin/login');
});

it('redirects an anonymous visitor away from the client area', function (): void {
    $this->get('/client')->assertRedirect('/login');
});

it('answers an unauthenticated api request with the error envelope', function (): void {
    Route::middleware(['api', 'auth:sanctum'])
        ->get('/api/v1/_test/private', fn (): array => []);

    $this->getJson('/api/v1/_test/private')
        ->assertStatus(401)
        ->assertJsonPath('error.code', 'unauthenticated');
});

it('refuses a signed-in user without the permission', function (): void {
    $this->actingAs(StaffUser::factory()->create())
        ->get('/admin')
        ->assertForbidden();
});

it('renders the admin dashboard for a permitted user', function (): void {
    $user = StaffUser::factory()->create();
    $user->assignRole(SystemRole::Support);

    $this->actingAs($user->fresh())
        ->get('/admin')
        ->assertOk()
        ->assertInertia(
            fn (AssertableInertia $page): AssertableInertia => $page
                ->component('Admin/Dashboard')
                ->has('environment')
                ->has('auth.permissions'),
        );
});

it('renders the client dashboard for any signed-in user', function (): void {
    $this->actingAs(StaffUser::factory()->create())
        ->get('/client')
        ->assertOk()
        ->assertInertia(
            fn (AssertableInertia $page): AssertableInertia => $page->component('Client/Dashboard'),
        );
});

it('never shares a secret with the front end', function (): void {
    $user = StaffUser::factory()->create();
    $user->assignRole(SystemRole::Support);

    $this->actingAs($user->fresh())
        ->get('/admin')
        ->assertOk()
        ->assertInertia(
            fn (AssertableInertia $page): AssertableInertia => $page->missing('auth.user.password'),
        );
});

it('serves the storefront fallback template to anonymous visitors', function (): void {
    $this->get('/')
        ->assertOk()
        ->assertSee(config('app.name'), escape: false);
});
