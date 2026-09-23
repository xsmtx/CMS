<?php

declare(strict_types=1);

use App\Application\Access\SyncPermissions;
use App\Application\Automation\Runs\SendLicenceHeartbeat;
use App\Application\Licensing\InstallationIdentity;
use App\Application\Licensing\LicencePublicKey;
use App\Application\Licensing\LicenceState;
use App\Application\Licensing\Licensing;
use App\Domain\Access\PermissionRegistry;
use App\Domain\Access\SystemRole;
use App\Domain\Health\HealthState;
use App\Domain\Licensing\Contracts\LicenceClient;
use App\Domain\Licensing\Exceptions\LicenceUnreachable;
use App\Infrastructure\Health\Checks\LicenceCheck;
use App\Infrastructure\Identity\Models\StaffUser;
use Carbon\CarbonImmutable;
use Database\Seeders\ProviderOrganizationSeeder;
use Database\Seeders\SystemRoleSeeder;
use Inertia\Testing\AssertableInertia;
use Tests\Support\FakeLicenceClient;

/**
 * The licence screen, the heartbeat task and the health check.
 *
 * Two rules are under test throughout:
 *
 * - **The licence key and the public key never reach the browser.** The screen
 *   says *whether* things are configured, never what they are; configuration on
 *   a screen is configuration in a screenshot.
 * - **Owner only.** An Administrator holds every staff permission there is by
 *   design, and a reseller's Administrator is an Administrator — so a
 *   permission for this would let a reseller release the installation's licence
 *   and turn the vendor mark back on for the provider.
 */
beforeEach(function (): void {
    $this->withoutVite();

    $this->seed(ProviderOrganizationSeeder::class);
    app(SyncPermissions::class)->handle(app(PermissionRegistry::class));
    $this->seed(SystemRoleSeeder::class);

    $pair = sodium_crypto_sign_keypair();
    $this->signingKey = sodium_crypto_sign_secretkey($pair);
    $this->publicKeyBase64 = base64_encode(sodium_crypto_sign_publickey($pair));

    $this->keyPath = storage_path('framework/testing/licence-'.bin2hex(random_bytes(6)).'.pub');

    if (! is_dir(dirname($this->keyPath))) {
        mkdir(dirname($this->keyPath), 0o777, true);
    }

    file_put_contents($this->keyPath, $this->publicKeyBase64);

    config()->set('platform.licensing.public_key_path', $this->keyPath);
    config()->set('platform.licensing.api_url', 'https://licences.example.test');
    config()->set('platform.licensing.key', 'LIC-SECRET-0001');
    config()->set('platform.licensing.grace_days', 30);

    $this->client = new FakeLicenceClient;
    $this->app->instance(LicenceClient::class, $this->client);
    $this->app->forgetInstance(LicencePublicKey::class);

    $this->owner = StaffUser::factory()->create();
    $this->owner->assignRole(SystemRole::SuperAdmin);
    $this->owner = $this->owner->fresh();

    $this->administrator = StaffUser::factory()->create();
    $this->administrator->assignRole(SystemRole::Administrator);
    $this->administrator = $this->administrator->fresh();
});

afterEach(function (): void {
    if (property_exists($this, 'keyPath') && $this->keyPath !== null && is_file($this->keyPath)) {
        unlink($this->keyPath);
    }
});

/**
 * @param  array<string, mixed>  $overrides
 */
function screenToken(string $secretKey, string $installationId, array $overrides = []): string
{
    $now = CarbonImmutable::now();

    $claims = [
        'licence_id' => 'lic_0001',
        'installation_id' => $installationId,
        'edition' => 'enterprise',
        'status' => 'active',
        'excluded' => [],
        'limits' => ['max_staff_users' => 25],
        'issued_at' => $now->toIso8601String(),
        'expires_at' => $now->addYear()->toIso8601String(),
        'heartbeat_by' => $now->addDays(7)->toIso8601String(),
        ...$overrides,
    ];

    $payload = json_encode($claims, JSON_THROW_ON_ERROR);
    $encode = static fn (string $raw): string => rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');

    return $encode($payload).'.'.$encode(sodium_crypto_sign_detached($payload, $secretKey));
}

it('shows the owner whether things are configured, never what they are', function (): void {
    $response = $this->actingAs($this->owner, 'staff')->get('/admin/licence');

    $response->assertOk()->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
        ->component('Admin/Licence/Index')
        ->where('configured.api', true)
        ->where('configured.key', true)
        ->where('configured.publicKey', true)
        ->where('licence.configured', false));

    $body = (string) $response->getContent();

    // The two things that must never be on a screen.
    expect($body)->not->toContain('LIC-SECRET-0001');
    expect($body)->not->toContain('licences.example.test');
    // Public by definition, and still never printed: a page that prints key
    // material teaches an operator that pages print key material.
    expect($body)->not->toContain($this->publicKeyBase64);
    expect($body)->not->toContain($this->keyPath);
});

it('gives the owner the installation identity to quote to the vendor', function (): void {
    $this->actingAs($this->owner, 'staff')
        ->get('/admin/licence')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->where('installation.id', app(InstallationIdentity::class)->id())
            // Three claims, and no more: no paths, no database name, no keys.
            ->has('installation.claims', 3));
});

/**
 * The gate that cannot be a permission. This administrator holds every staff
 * permission there is.
 */
it('is shut to an administrator who is not the owner', function (): void {
    expect($this->administrator->effectivePermissions())->toContain('settings.manage');

    $this->actingAs($this->administrator, 'staff');

    $this->get('/admin/licence')->assertForbidden();
    $this->post('/admin/licence/activate', ['licence_key' => 'LIC-X'])->assertForbidden();
    $this->post('/admin/licence/heartbeat')->assertForbidden();
    $this->post('/admin/licence/deactivate')->assertForbidden();
});

it('activates from the screen and reports the edition', function (): void {
    $installationId = app(InstallationIdentity::class)->id();
    $this->client->nextToken = screenToken($this->signingKey, $installationId);

    $this->actingAs($this->owner, 'staff')
        ->from('/admin/licence')
        ->post('/admin/licence/activate', ['licence_key' => 'LIC-TEST-0001'])
        ->assertRedirect('/admin/licence')
        ->assertSessionHas('status', fn (string $message): bool => str_contains($message, 'enterprise'));

    expect(LicenceState::load()->isLive())->toBeTrue();
});

/**
 * A refusal goes on the field, because the operator typed a key and the key is
 * what this is about.
 */
it('puts a refused token on the licence key field', function (): void {
    $installationId = app(InstallationIdentity::class)->id();

    $impostor = sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair());
    $this->client->nextToken = screenToken($impostor, $installationId);

    $this->actingAs($this->owner, 'staff')
        ->from('/admin/licence')
        ->post('/admin/licence/activate', ['licence_key' => 'LIC-TEST-0001'])
        ->assertSessionHasErrors('licence_key');

    expect(LicenceState::load()->configured)->toBeFalse();
});

it('says plainly when a manual check could not reach the vendor', function (): void {
    $installationId = app(InstallationIdentity::class)->id();
    $this->client->nextToken = screenToken($this->signingKey, $installationId);

    $this->actingAs($this->owner, 'staff');
    app(Licensing::class)->activate('LIC-TEST-0001');

    $this->client->failWith = LicenceUnreachable::transport('heartbeat', 'ConnectionException');

    // Not a success message that was not true.
    $this->from('/admin/licence')
        ->post('/admin/licence/heartbeat')
        ->assertSessionHas('error');
});

// --- the heartbeat task -----------------------------------------------------

/**
 * Both properties every automation task here needs: run it twice and the second
 * changes nothing, and one failure does not make the run a failure.
 */
it('heartbeats once and then finds nothing due', function (): void {
    $installationId = app(InstallationIdentity::class)->id();
    $this->client->nextToken = screenToken($this->signingKey, $installationId, [
        'heartbeat_by' => CarbonImmutable::now()->subDay()->toIso8601String(),
    ]);

    app(Licensing::class)->activate('LIC-TEST-0001');

    // Due, because the deadline the token carried is already past.
    $this->client->nextToken = screenToken($this->signingKey, $installationId);

    $first = app(SendLicenceHeartbeat::class)->handle();

    expect($first->changed)->toBe(1);

    // Not due: the fresh token's deadline is a week away.
    $second = app(SendLicenceHeartbeat::class)->handle();

    expect($second->changed)->toBe(0);
    expect($second->examined)->toBe(0);
});

it('does nothing at all on an installation with no licence', function (): void {
    $summary = app(SendLicenceHeartbeat::class)->handle();

    expect($summary->examined)->toBe(0);
    expect($summary->failed)->toBe(0);
});

/**
 * A vendor outage is not a failed run. A red row every hour during one is a red
 * row nobody reads, and the entitlements did not move.
 */
it('completes the run when the vendor is unreachable', function (): void {
    $installationId = app(InstallationIdentity::class)->id();
    $this->client->nextToken = screenToken($this->signingKey, $installationId, [
        'heartbeat_by' => CarbonImmutable::now()->subDay()->toIso8601String(),
    ]);

    app(Licensing::class)->activate('LIC-TEST-0001');

    $this->client->failWith = LicenceUnreachable::transport('heartbeat', 'ConnectionException');

    $summary = app(SendLicenceHeartbeat::class)->handle();

    expect($summary->failed)->toBe(0);
    expect($summary->changed)->toBe(0);
    expect(LicenceState::load()->isLive())->toBeTrue();
});

/**
 * A refused token *is* a failure. It is the one licensing outcome that belongs
 * on the automation screen, because it is a security event rather than weather.
 */
it('fails the run when a token is refused', function (): void {
    $installationId = app(InstallationIdentity::class)->id();
    $this->client->nextToken = screenToken($this->signingKey, $installationId, [
        'heartbeat_by' => CarbonImmutable::now()->subDay()->toIso8601String(),
    ]);

    app(Licensing::class)->activate('LIC-TEST-0001');

    $impostor = sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair());
    $this->client->nextToken = screenToken($impostor, $installationId);

    $summary = app(SendLicenceHeartbeat::class)->handle();

    expect($summary->failed)->toBe(1);
});

// --- the health check -------------------------------------------------------

it('reads an unlicensed installation as healthy, not broken', function (): void {
    $report = (new LicenceCheck)->run();

    // A self-hosted installation with no commercial relationship is a
    // supported way to run this.
    expect($report->state)->toBe(HealthState::Ok);
});

it('reads a licence inside grace as degraded', function (): void {
    $installationId = app(InstallationIdentity::class)->id();
    $this->client->nextToken = screenToken($this->signingKey, $installationId, [
        'heartbeat_by' => CarbonImmutable::now()->subDay()->toIso8601String(),
    ]);

    app(Licensing::class)->activate('LIC-TEST-0001');

    $report = (new LicenceCheck)->run();

    // The only warning anybody gets before the vendor mark comes back.
    expect($report->state)->toBe(HealthState::Degraded);
    expect($report->detail)->toContain('grace');
});

it('reads a revoked licence as failing', function (): void {
    $installationId = app(InstallationIdentity::class)->id();
    $this->client->nextToken = screenToken($this->signingKey, $installationId, [
        'status' => 'revoked',
    ]);

    app(Licensing::class)->activate('LIC-TEST-0001');

    $report = (new LicenceCheck)->run();

    expect($report->state)->toBe(HealthState::Failing);
    // And it says what that actually costs, which is very little.
    expect($report->detail)->toContain('nothing else has changed');
});

/**
 * The rule every check in this platform follows: a health check never returns a
 * configuration value.
 */
it('never returns a configuration value from the licence check', function (): void {
    $installationId = app(InstallationIdentity::class)->id();
    $this->client->nextToken = screenToken($this->signingKey, $installationId);

    app(Licensing::class)->activate('LIC-TEST-0001');

    $report = (new LicenceCheck)->run();
    $serialised = json_encode([$report->detail, $report->measurements]);

    expect($serialised)->not->toContain('LIC-SECRET-0001');
    expect($serialised)->not->toContain('licences.example.test');
    expect($serialised)->not->toContain($this->keyPath);
});
