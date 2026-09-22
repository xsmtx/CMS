<?php

declare(strict_types=1);

use App\Application\Access\SyncPermissions;
use App\Domain\Access\PermissionRegistry;
use App\Domain\Access\SystemRole;
use App\Infrastructure\Identity\Models\Contact;
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

it('refuses a signed-in staff member without the permission', function (): void {
    $this->actingAs(StaffUser::factory()->create(), 'staff')
        ->get('/admin')
        ->assertForbidden();
});

it('renders the admin dashboard for a permitted staff member', function (): void {
    $staff = StaffUser::factory()->create();
    $staff->assignRole(SystemRole::Support);

    $this->actingAs($staff->fresh(), 'staff')
        ->get('/admin')
        ->assertOk()
        ->assertInertia(
            fn (AssertableInertia $page): AssertableInertia => $page
                ->component('Admin/Dashboard')
                ->has('environment')
                ->has('auth.permissions')
                ->where('auth.guard', 'staff'),
        );
});

it('renders the client dashboard for a signed-in contact', function (): void {
    $this->actingAs(Contact::factory()->create(), 'client')
        ->get('/client')
        ->assertOk()
        ->assertInertia(
            fn (AssertableInertia $page): AssertableInertia => $page
                ->component('Client/Dashboard')
                ->where('auth.guard', 'client'),
        );
});

it('keeps a staff session out of the client area', function (): void {
    // The two guards do not share a session, so a staff member browsing to
    // the client area is simply an anonymous visitor there.
    $this->actingAs(StaffUser::factory()->create(), 'staff')
        ->get('/client')
        ->assertRedirect('/login');
});

it('keeps a contact session out of the admin area', function (): void {
    $this->actingAs(Contact::factory()->create(), 'client')
        ->get('/admin')
        ->assertRedirect('/admin/login');
});

it('never shares a secret with the front end', function (): void {
    $staff = StaffUser::factory()->create();
    $staff->assignRole(SystemRole::Support);

    $this->actingAs($staff->fresh(), 'staff')
        ->get('/admin')
        ->assertOk()
        ->assertInertia(
            fn (AssertableInertia $page): AssertableInertia => $page
                ->missing('auth.user.password')
                ->missing('auth.user.two_factor_secret'),
        );
});

it('serves the storefront fallback template to anonymous visitors', function (): void {
    $this->get('/')
        ->assertOk()
        ->assertSee(config('app.name'), escape: false);
});
