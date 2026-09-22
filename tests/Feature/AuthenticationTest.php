<?php

declare(strict_types=1);

use App\Domain\Identity\AccountStatus;
use App\Domain\Identity\LoginFailureReason;
use App\Infrastructure\Crm\Models\Customer;
use App\Infrastructure\Identity\Models\Contact;
use App\Infrastructure\Identity\Models\LoginHistory;
use App\Infrastructure\Identity\Models\StaffUser;
use App\Infrastructure\Organizations\Models\Organization;
use App\Support\Organizations\OrganizationContext;
use Illuminate\Support\Facades\RateLimiter;

beforeEach(function (): void {
    $this->withoutVite();
    RateLimiter::clear('login:staff:owner@example.test|127.0.0.1');
});

function staffUser(array $attributes = []): StaffUser
{
    return StaffUser::factory()->create([
        'email' => 'owner@example.test',
        'password' => 'correct-horse-battery-staple-1!',
        ...$attributes,
    ]);
}

function portalContact(array $attributes = []): Contact
{
    return Contact::factory()->create([
        'email' => 'customer@example.test',
        'password' => 'correct-horse-battery-staple-1!',
        ...$attributes,
    ]);
}

it('signs a staff member in', function (): void {
    $staff = staffUser();

    $this->post('/admin/login', [
        'email' => $staff->email,
        'password' => 'correct-horse-battery-staple-1!',
    ])->assertRedirect('/admin');

    expect(auth('staff')->check())->toBeTrue()
        ->and(auth('client')->check())->toBeFalse();
});

it('signs a contact in on the client guard', function (): void {
    $contact = portalContact();

    $this->post('/login', [
        'email' => $contact->email,
        'password' => 'correct-horse-battery-staple-1!',
    ])->assertRedirect('/client');

    expect(auth('client')->check())->toBeTrue()
        ->and(auth('staff')->check())->toBeFalse();
});

it('refuses staff credentials on the client sign-in form', function (): void {
    $staff = staffUser();

    $this->post('/login', [
        'email' => $staff->email,
        'password' => 'correct-horse-battery-staple-1!',
    ])->assertSessionHasErrors('email');

    expect(auth('client')->check())->toBeFalse()
        ->and(auth('staff')->check())->toBeFalse();
});

it('refuses contact credentials on the admin sign-in form', function (): void {
    $contact = portalContact();

    $this->post('/admin/login', [
        'email' => $contact->email,
        'password' => 'correct-horse-battery-staple-1!',
    ])->assertSessionHasErrors('email');

    expect(auth('staff')->check())->toBeFalse();
});

it('gives the same message whether the account exists or not', function (): void {
    staffUser();

    $unknown = $this->post('/admin/login', [
        'email' => 'nobody@example.test',
        'password' => 'whatever-1!',
    ]);

    $wrongPassword = $this->post('/admin/login', [
        'email' => 'owner@example.test',
        'password' => 'whatever-1!',
    ]);

    expect($unknown->exception?->errors()['email'])
        ->toBe($wrongPassword->exception?->errors()['email']);
});

it('records a successful attempt in login history', function (): void {
    $staff = staffUser();

    $this->post('/admin/login', [
        'email' => $staff->email,
        'password' => 'correct-horse-battery-staple-1!',
    ]);

    $entry = LoginHistory::query()->withoutGlobalScope('organization')->sole();

    expect($entry->successful)->toBeTrue()
        ->and($entry->subject_id)->toBe($staff->id)
        ->and($entry->correlation_id)->not->toBeNull();
});

it('records why a failed attempt failed, without telling the caller', function (): void {
    staffUser();

    $this->post('/admin/login', [
        'email' => 'owner@example.test',
        'password' => 'wrong-password-1!',
    ])->assertSessionHasErrors('email');

    $entry = LoginHistory::query()->withoutGlobalScope('organization')->sole();

    expect($entry->successful)->toBeFalse()
        ->and($entry->failure_reason)->toBe(LoginFailureReason::InvalidPassword);
});

it('records an attempt on an address that does not exist', function (): void {
    $this->post('/admin/login', [
        'email' => 'ghost@example.test',
        'password' => 'wrong-password-1!',
    ]);

    $entry = LoginHistory::query()->withoutGlobalScope('organization')->sole();

    expect($entry->failure_reason)->toBe(LoginFailureReason::UnknownIdentity)
        ->and($entry->subject_id)->toBeNull()
        ->and($entry->email_attempted)->toBe('ghost@example.test');
});

it('refuses a suspended account', function (): void {
    staffUser(['status' => AccountStatus::Suspended->value]);

    $this->post('/admin/login', [
        'email' => 'owner@example.test',
        'password' => 'correct-horse-battery-staple-1!',
    ])->assertSessionHasErrors('email');

    expect(auth('staff')->check())->toBeFalse()
        ->and(LoginHistory::query()->withoutGlobalScope('organization')->sole()->failure_reason)
        ->toBe(LoginFailureReason::AccountSuspended);
});

it('refuses a contact whose portal access was withdrawn', function (): void {
    $customer = Customer::factory()->create();

    Contact::factory()->forCustomer($customer)->create([
        'email' => 'nologin@example.test',
        'password' => 'correct-horse-battery-staple-1!',
        'portal_access' => false,
    ]);

    $this->post('/login', [
        'email' => 'nologin@example.test',
        'password' => 'correct-horse-battery-staple-1!',
    ])->assertSessionHasErrors('email');

    expect(LoginHistory::query()->withoutGlobalScope('organization')->sole()->failure_reason)
        ->toBe(LoginFailureReason::NoPortalAccess);
});

it('throttles repeated failures for one identity', function (): void {
    staffUser();

    for ($attempt = 0; $attempt < 5; $attempt++) {
        $this->post('/admin/login', [
            'email' => 'owner@example.test',
            'password' => 'wrong-password-1!',
        ]);
    }

    $response = $this->post('/admin/login', [
        'email' => 'owner@example.test',
        'password' => 'correct-horse-battery-staple-1!',
    ]);

    $response->assertSessionHasErrors('email');

    expect(auth('staff')->check())->toBeFalse()
        ->and(LoginHistory::query()->withoutGlobalScope('organization')
            ->where('failure_reason', LoginFailureReason::Throttled->value)->count())
        ->toBeGreaterThan(0);
});

it('regenerates the session identifier on sign-in', function (): void {
    $staff = staffUser();

    $this->get('/admin/login');
    $before = session()->getId();

    $this->post('/admin/login', [
        'email' => $staff->email,
        'password' => 'correct-horse-battery-staple-1!',
    ]);

    expect(session()->getId())->not->toBe($before);
});

it('records the session so it can be revoked later', function (): void {
    $staff = staffUser();

    $this->post('/admin/login', [
        'email' => $staff->email,
        'password' => 'correct-horse-battery-staple-1!',
    ]);

    expect($staff->fresh()->authenticatedSessions()->count())->toBe(1);
});

it('stamps the last sign-in time', function (): void {
    $staff = staffUser();

    expect($staff->last_login_at)->toBeNull();

    $this->post('/admin/login', [
        'email' => $staff->email,
        'password' => 'correct-horse-battery-staple-1!',
    ]);

    expect($staff->fresh()->last_login_at)->not->toBeNull();
});

it('signs out and forgets the session record', function (): void {
    $staff = staffUser();

    $this->actingAs($staff, 'staff')->post('/admin/logout')->assertRedirect('/admin/login');

    expect(auth('staff')->check())->toBeFalse();
});

it('sends a guest to the sign-in page for their own area', function (): void {
    $this->get('/admin/security')->assertRedirect('/admin/login');
    $this->get('/security')->assertRedirect('/login');
});

it('sends a signed-in user away from the sign-in page', function (): void {
    $staff = staffUser();

    $this->actingAs($staff, 'staff')->get('/admin/login')->assertRedirect('/admin');
});

it('establishes the organization boundary from the guard that signed in', function (): void {
    $provider = Organization::query()->withoutGlobalScope('organization')
        ->where('type', 'provider')->first()
        ?? Organization::factory()->provider()->create();

    $staff = StaffUser::factory()->forOrganization($provider)->create([
        'email' => 'boundary@example.test',
        'password' => 'correct-horse-battery-staple-1!',
    ]);

    $this->post('/admin/login', [
        'email' => $staff->email,
        'password' => 'correct-horse-battery-staple-1!',
    ]);

    expect(app(OrganizationContext::class)->id())->toBe($provider->id);
});
