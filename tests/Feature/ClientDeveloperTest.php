<?php

declare(strict_types=1);

use App\Application\Access\SyncPermissions;
use App\Application\Identity\Impersonator;
use App\Domain\Access\PermissionRegistry;
use App\Domain\Access\SystemRole;
use App\Infrastructure\Crm\Models\Customer;
use App\Infrastructure\Identity\Models\Contact;
use Database\Seeders\ProviderOrganizationSeeder;
use Database\Seeders\SystemRoleSeeder;
use Inertia\Testing\AssertableInertia;
use Laravel\Sanctum\PersonalAccessToken;

beforeEach(function (): void {
    $this->withoutVite();

    $this->seed(ProviderOrganizationSeeder::class);
    app(SyncPermissions::class)->handle(app(PermissionRegistry::class));
    $this->seed(SystemRoleSeeder::class);

    $this->customer = Customer::factory()->create();

    $this->owner = Contact::factory()->forCustomer($this->customer)->primary()->create();
    $this->owner->assignRole(SystemRole::AccountOwner);
    $this->owner = $this->owner->fresh();

    $this->member = Contact::factory()->forCustomer($this->customer)->create();
    $this->member->assignRole(SystemRole::PortalMember);
    $this->member = $this->member->fresh();
});

it('issues a token and shows it exactly once', function (): void {
    $response = $this->actingAs($this->owner, 'client')
        ->post('/client/developer/tokens', ['name' => 'Backup script']);

    $response->assertRedirect()->assertSessionHas('issuedToken');

    $plain = (string) session('issuedToken');

    expect($plain)->toContain('|')
        // What is stored is a hash. There is nothing to show a second time
        // even if a screen asked for it.
        ->and(PersonalAccessToken::query()->sole()->token)->not->toBe($plain);

    // The page the redirect lands on hands it over once...
    $this->actingAs($this->owner, 'client')
        ->get('/client/developer/tokens')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->component('Client/Developer/Tokens')
            ->has('tokens', 1)
            ->where('tokens.0.name', 'Backup script')
            ->where('issued', $plain));

    // ...and a refresh does not. It was flashed, not kept.
    $this->actingAs($this->owner, 'client')
        ->get('/client/developer/tokens')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->where('issued', null))
        ->assertDontSee($plain);
});

it('honours an expiry when one is asked for', function (): void {
    $this->actingAs($this->owner, 'client')
        ->post('/client/developer/tokens', ['name' => 'Temporary', 'expires_in_days' => 30]);

    expect(PersonalAccessToken::query()->sole()->expires_at)->not->toBeNull();
});

it('issues a token that does not expire when none is asked for', function (): void {
    $this->actingAs($this->owner, 'client')
        ->post('/client/developer/tokens', ['name' => 'Permanent']);

    expect(PersonalAccessToken::query()->sole()->expires_at)->toBeNull();
});

it('needs a name', function (): void {
    $this->actingAs($this->owner, 'client')
        ->post('/client/developer/tokens', ['name' => ''])
        ->assertSessionHasErrors('name');
});

it('revokes a token', function (): void {
    $this->actingAs($this->owner, 'client')->post('/client/developer/tokens', ['name' => 'Gone']);

    $token = PersonalAccessToken::query()->sole();

    $this->actingAs($this->owner, 'client')
        ->delete("/client/developer/tokens/{$token->getKey()}")
        ->assertRedirect();

    expect(PersonalAccessToken::query()->count())->toBe(0);
});

it('will not revoke somebody elses token', function (): void {
    $stranger = Contact::factory()->create();
    $token = $stranger->createToken('Theirs');

    $this->actingAs($this->owner, 'client')
        ->delete("/client/developer/tokens/{$token->accessToken->getKey()}")
        ->assertNotFound();

    expect(PersonalAccessToken::query()->count())->toBe(1);
});

it('refuses tokens to a portal member', function (): void {
    $this->actingAs($this->member, 'client')
        ->get('/client/developer/tokens')
        ->assertForbidden();
});

it('refuses to issue a token while a staff member is impersonating', function (): void {
    // Whatever an operator is impersonating a customer to fix, it is not to
    // walk out with a credential that outlives the session.
    $this->actingAs($this->owner, 'client')
        ->withSession([Impersonator::SESSION_KEY => [
            'subject_name' => 'Ines Caetano',
            'impersonator_name' => 'An operator',
        ]])
        ->post('/client/developer/tokens', ['name' => 'Should not exist'])
        ->assertForbidden();

    expect(PersonalAccessToken::query()->count())->toBe(0);
});
