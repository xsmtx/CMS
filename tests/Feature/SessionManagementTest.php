<?php

declare(strict_types=1);

use App\Application\Identity\SessionRegistry;
use App\Domain\Identity\Guard;
use App\Infrastructure\Identity\Models\AuthenticatedSession;
use App\Infrastructure\Identity\Models\StaffUser;
use Inertia\Testing\AssertableInertia;

beforeEach(function (): void {
    $this->withoutVite();

    $this->staff = StaffUser::factory()->create([
        'email' => 'sessions@example.test',
        'password' => 'correct-horse-battery-staple-1!',
    ]);
});

function seedSession(StaffUser $staff, string $sessionId): AuthenticatedSession
{
    return AuthenticatedSession::factory()->create([
        'organization_id' => $staff->organization_id,
        'subject_type' => $staff->getMorphClass(),
        'subject_id' => $staff->id,
        'session_id' => $sessionId,
    ]);
}

it('records a session row when someone signs in', function (): void {
    $this->post('/admin/login', [
        'email' => $this->staff->email,
        'password' => 'correct-horse-battery-staple-1!',
    ]);

    $session = $this->staff->fresh()->authenticatedSessions()->sole();

    expect($session->guard)->toBe(Guard::Staff)
        ->and($session->ip_address)->not->toBeNull();
});

it('lists the sessions on the security page', function (): void {
    seedSession($this->staff, 'another-device');

    $this->actingAs($this->staff, 'staff')
        ->get('/admin/security')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->component('Security/Index')
            ->has('sessions', 1)
            ->where('sessions.0.current', false));
});

it('keeps only the current session when revoking the others', function (): void {
    // Driven through the registry rather than two HTTP calls: the test
    // client does not carry the session cookie between requests, so the
    // identity of "current" can only be asserted where it is decided.
    $keep = seedSession($this->staff, 'this-device');
    seedSession($this->staff, 'phone');
    seedSession($this->staff, 'laptop');

    $revoked = app(SessionRegistry::class)->revokeOthers($this->staff, 'this-device');

    $remaining = $this->staff->fresh()->authenticatedSessions()->get();

    expect($revoked)->toBe(2)
        ->and($remaining)->toHaveCount(1)
        ->and($remaining->first()->id)->toBe($keep->id);
});

it('marks the current session and no other', function (): void {
    $current = seedSession($this->staff, 'this-device');
    $other = seedSession($this->staff, 'phone');

    expect($current->isCurrent('this-device'))->toBeTrue()
        ->and($other->isCurrent('this-device'))->toBeFalse();
});

it('revokes other sessions from the security page', function (): void {
    seedSession($this->staff, 'phone');
    seedSession($this->staff, 'laptop');

    $this->actingAs($this->staff, 'staff')
        ->delete('/admin/security/sessions')
        ->assertRedirect();

    expect($this->staff->fresh()->authenticatedSessions()->count())->toBe(0);
});

it('revokes one named session', function (): void {
    $this->actingAs($this->staff, 'staff');

    $other = seedSession($this->staff, 'other-device');

    $this->delete('/admin/security/sessions/'.$other->id)->assertRedirect();

    expect(AuthenticatedSession::query()->find($other->id))->toBeNull();
});

it('refuses to revoke a session belonging to someone else', function (): void {
    $theirs = seedSession(StaffUser::factory()->create(), 'their-device');

    $this->actingAs($this->staff, 'staff')
        ->delete('/admin/security/sessions/'.$theirs->id)
        ->assertNotFound();

    expect(AuthenticatedSession::query()->find($theirs->id))->not->toBeNull();
});

it('ends every other session when the password changes', function (): void {
    seedSession($this->staff, 'phone');
    seedSession($this->staff, 'laptop');

    $this->actingAs($this->staff, 'staff')
        ->put('/admin/security/password', [
            'current_password' => 'correct-horse-battery-staple-1!',
            'password' => 'a-completely-different-1!',
            'password_confirmation' => 'a-completely-different-1!',
        ])
        ->assertSessionHasNoErrors();

    expect($this->staff->fresh()->authenticatedSessions()->count())->toBe(0);
});

it('refuses a password change without the current password', function (): void {
    $this->actingAs($this->staff, 'staff')
        ->put('/admin/security/password', [
            'current_password' => 'not-the-right-one-1!',
            'password' => 'a-completely-different-1!',
            'password_confirmation' => 'a-completely-different-1!',
        ])
        ->assertSessionHasErrors('current_password');
});

it('refuses a new password identical to the current one', function (): void {
    $this->actingAs($this->staff, 'staff')
        ->put('/admin/security/password', [
            'current_password' => 'correct-horse-battery-staple-1!',
            'password' => 'correct-horse-battery-staple-1!',
            'password_confirmation' => 'correct-horse-battery-staple-1!',
        ])
        ->assertSessionHasErrors('password');
});

it('stamps when the password was last changed', function (): void {
    expect($this->staff->password_changed_at)->toBeNull();

    $this->actingAs($this->staff, 'staff')->put('/admin/security/password', [
        'current_password' => 'correct-horse-battery-staple-1!',
        'password' => 'a-completely-different-1!',
        'password_confirmation' => 'a-completely-different-1!',
    ]);

    expect($this->staff->fresh()->password_changed_at)->not->toBeNull();
});
