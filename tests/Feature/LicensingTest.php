<?php

declare(strict_types=1);

use App\Application\Licensing\InstallationIdentity;
use App\Application\Licensing\LicencePublicKey;
use App\Application\Licensing\LicenceState;
use App\Application\Licensing\Licensing;
use App\Application\Licensing\VerifyLicenceToken;
use App\Domain\Licensing\Contracts\Entitlements;
use App\Domain\Licensing\Contracts\LicenceClient;
use App\Domain\Licensing\Exceptions\LicenceRefused;
use App\Domain\Licensing\Exceptions\LicenceUnreachable;
use App\Domain\Licensing\Feature;
use App\Domain\Licensing\LicenceStatus;
use App\Domain\Licensing\LicenceToken;
use App\Infrastructure\Audit\Models\AuditLog;
use App\Infrastructure\Licensing\LicensedEntitlements;
use App\Infrastructure\Platform\Models\PlatformState;
use Carbon\CarbonImmutable;
use Tests\Support\FakeLicenceClient;

/**
 * The licence, from the installation's side.
 *
 * ADR 0013 decides the shape and this file tests the two properties that make
 * it worth anything:
 *
 * 1. **The installation can prove a licence and cannot mint one.** A token is
 *    Ed25519-signed by a key that exists only on vendor infrastructure and
 *    verified locally against the public half. Every way of getting a token
 *    accepted without that signature is a test here.
 * 2. **A vendor outage is not a customer outage.** The grace period is the
 *    commercial promise, and the tests below hold the entitlements still while
 *    the licence server is unreachable.
 *
 * The vendor's licence API is a separate application this repository does not
 * contain. What is faked is the transport; what is real is the signing, the
 * verification, the state and the grace arithmetic — which is the half that
 * decides whether a paying customer keeps working.
 */
beforeEach(function (): void {
    $this->withoutVite();

    // A throwaway key pair per test. The private half exists here only because
    // a test has to stand in for the vendor; nothing in the product can sign.
    $pair = sodium_crypto_sign_keypair();

    $this->signingKey = sodium_crypto_sign_secretkey($pair);
    $this->publicKey = sodium_crypto_sign_publickey($pair);

    $this->keyPath = storage_path('framework/testing/licence-'.bin2hex(random_bytes(6)).'.pub');

    if (! is_dir(dirname($this->keyPath))) {
        mkdir(dirname($this->keyPath), 0o777, true);
    }

    file_put_contents($this->keyPath, base64_encode($this->publicKey));

    config()->set('platform.licensing.public_key_path', $this->keyPath);
    config()->set('platform.licensing.api_url', 'https://licences.example.test');
    config()->set('platform.licensing.key', 'LIC-TEST-0001');
    config()->set('platform.licensing.grace_days', 30);

    $this->client = new FakeLicenceClient;

    $this->app->instance(LicenceClient::class, $this->client);
    $this->app->forgetInstance(LicencePublicKey::class);
});

afterEach(function (): void {
    if (property_exists($this, 'keyPath') && $this->keyPath !== null && is_file($this->keyPath)) {
        unlink($this->keyPath);
    }
});

/**
 * A token, signed the way the vendor would sign one.
 *
 * @param  array<string, mixed>  $claims
 */
function signToken(string $secretKey, array $claims): string
{
    $payload = json_encode($claims, JSON_THROW_ON_ERROR);
    $signature = sodium_crypto_sign_detached($payload, $secretKey);

    $encode = static fn (string $raw): string => rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');

    return $encode($payload).'.'.$encode($signature);
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function licenceClaims(string $installationId, array $overrides = []): array
{
    $now = CarbonImmutable::now();

    return [
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
}

// --- identity ---------------------------------------------------------------

/**
 * The identity is in the database on purpose. Restoring a production backup
 * into a second environment then produces two installations claiming one ID,
 * which is exactly the anomaly the licence server should see — an identity
 * that regenerated itself on restore would hide the one event worth noticing.
 */
it('mints one installation identity and keeps it', function (): void {
    $identity = app(InstallationIdentity::class);

    $first = $identity->id();

    expect($first)->not->toBe('');
    expect($identity->id())->toBe($first);

    // Survives a cache flush, because it was never in the cache.
    cache()->flush();
    expect(app(InstallationIdentity::class)->id())->toBe($first);

    expect(PlatformState::query()->find(InstallationIdentity::STATE_KEY))->not->toBeNull();
});

it('tells the vendor nothing but the host, the version and the PHP line', function (): void {
    $claims = app(InstallationIdentity::class)->claims();

    expect(array_keys($claims))->toBe(['host', 'version', 'php']);

    // No paths, no database name, no keys. A vendor does not need to know
    // where an installation keeps its files.
    $joined = implode(' ', $claims);

    expect($joined)->not->toContain(base_path());
    expect($joined)->not->toContain((string) config('database.connections.mariadb.database'));
});

// --- verification -----------------------------------------------------------

it('accepts a token this vendor signed', function (): void {
    $installationId = app(InstallationIdentity::class)->id();

    $token = app(VerifyLicenceToken::class)->handle(
        signToken($this->signingKey, licenceClaims($installationId)),
        $installationId,
    );

    expect($token)->toBeInstanceOf(LicenceToken::class);
    expect($token->edition)->toBe('enterprise');
    expect($token->status)->toBe(LicenceStatus::Active);
    expect($token->limit('max_staff_users'))->toBe(25);
    expect($token->limit('max_reseller_accounts'))->toBeNull();
});

/**
 * The property the whole design rests on. Somebody with the product's source,
 * its database and its configuration still cannot produce a token it accepts.
 */
it('refuses a token signed by anybody else', function (): void {
    $installationId = app(InstallationIdentity::class)->id();

    $impostor = sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair());

    expect(fn (): LicenceToken => app(VerifyLicenceToken::class)->handle(
        signToken($impostor, licenceClaims($installationId)),
        $installationId,
    ))->toThrow(LicenceRefused::class);
});

it('refuses a token whose claims were edited after signing', function (): void {
    $installationId = app(InstallationIdentity::class)->id();

    $token = signToken($this->signingKey, licenceClaims($installationId, ['edition' => 'starter']));

    [$payload, $signature] = explode('.', $token);

    // The payload swapped for a better one, keeping the signature. This is the
    // attack a signature over re-encoded JSON would let through.
    $forged = rtrim(strtr(base64_encode(
        json_encode(licenceClaims($installationId, ['edition' => 'enterprise']), JSON_THROW_ON_ERROR)
    ), '+/', '-_'), '=');

    expect(fn (): LicenceToken => app(VerifyLicenceToken::class)->handle(
        $forged.'.'.$signature,
        $installationId,
    ))->toThrow(LicenceRefused::class);
});

it('refuses a token issued to a different installation', function (): void {
    $installationId = app(InstallationIdentity::class)->id();

    expect(fn (): LicenceToken => app(VerifyLicenceToken::class)->handle(
        signToken($this->signingKey, licenceClaims('somebody-else')),
        $installationId,
    ))->toThrow(LicenceRefused::class);
});

/**
 * Either the clocks disagree or somebody is constructing tokens. Both are
 * worth a record and neither is worth accepting.
 */
it('refuses a token dated in the future', function (): void {
    $installationId = app(InstallationIdentity::class)->id();

    expect(fn (): LicenceToken => app(VerifyLicenceToken::class)->handle(
        signToken($this->signingKey, licenceClaims($installationId, [
            'issued_at' => CarbonImmutable::now()->addHours(2)->toIso8601String(),
        ])),
        $installationId,
    ))->toThrow(LicenceRefused::class);
});

/**
 * The replay. A captured response from before a downgrade is correctly signed,
 * unexpired and correctly addressed — the only thing wrong with it is that the
 * installation has already seen a newer one.
 */
it('refuses a token older than the one it already holds', function (): void {
    $installationId = app(InstallationIdentity::class)->id();

    $old = CarbonImmutable::now()->subMonth();

    expect(fn (): LicenceToken => app(VerifyLicenceToken::class)->handle(
        signToken($this->signingKey, licenceClaims($installationId, [
            'issued_at' => $old->toIso8601String(),
        ])),
        $installationId,
        previouslyIssuedAt: CarbonImmutable::now()->subDay(),
    ))->toThrow(LicenceRefused::class);
});

it('refuses a token when this distribution has no public key', function (): void {
    config()->set('platform.licensing.public_key_path');
    $this->app->forgetInstance(LicencePublicKey::class);

    $installationId = app(InstallationIdentity::class)->id();

    expect(fn (): LicenceToken => app(VerifyLicenceToken::class)->handle(
        signToken($this->signingKey, licenceClaims($installationId)),
        $installationId,
    ))->toThrow(LicenceRefused::class);
});

it('refuses rubbish without throwing something unhelpful', function (): void {
    $installationId = app(InstallationIdentity::class)->id();

    foreach (['', 'nonsense', 'a.b', 'only-one-part', '...'] as $rubbish) {
        expect(fn (): LicenceToken => app(VerifyLicenceToken::class)->handle($rubbish, $installationId))
            ->toThrow(LicenceRefused::class);
    }
});

// --- activation and the state ----------------------------------------------

it('activates, stores the state, and audits the licence rather than the key', function (): void {
    $installationId = app(InstallationIdentity::class)->id();

    $this->client->nextToken = signToken($this->signingKey, licenceClaims($installationId));

    $state = app(Licensing::class)->activate('LIC-TEST-0001');

    expect($state->configured)->toBeTrue();
    expect($state->edition)->toBe('enterprise');
    expect($state->isLive())->toBeTrue();

    // Grace runs from the heartbeat deadline, not from now: seven days plus
    // thirty is thirty-seven, which is the promise that makes this safe.
    expect($state->graceUntil?->toDateString())
        ->toBe(CarbonImmutable::now()->addDays(37)->toDateString());

    $record = AuditLog::query()->where('action', 'licensing.activated')->sole();

    expect($record->metadata['edition'])->toBe('enterprise');
    // The one secret in this whole context.
    expect(json_encode($record->metadata))->not->toContain('LIC-TEST-0001');
});

it('audits which refusal happened, not just that one did', function (): void {
    $installationId = app(InstallationIdentity::class)->id();

    $impostor = sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair());
    $this->client->nextToken = signToken($impostor, licenceClaims($installationId));

    expect(fn (): LicenceState => app(Licensing::class)->activate('LIC-TEST-0001'))
        ->toThrow(LicenceRefused::class);

    $record = AuditLog::query()->where('action', 'licensing.token.refused')->sole();

    expect($record->reason)->toContain('signed');
    expect(LicenceState::load()->configured)->toBeFalse();
});

// --- grace ------------------------------------------------------------------

/**
 * The commercial promise, and the reason `LicenceUnreachable` is a different
 * type from `LicenceRefused`: a vendor's DNS failure must never be a
 * customer's outage.
 */
it('keeps the entitlements exactly where they were when the server is unreachable', function (): void {
    $installationId = app(InstallationIdentity::class)->id();

    $this->client->nextToken = signToken($this->signingKey, licenceClaims($installationId, [
        'excluded' => ['advanced_reports'],
    ]));

    app(Licensing::class)->activate('LIC-TEST-0001');

    $this->client->failWith = LicenceUnreachable::transport('heartbeat', 'ConnectionException');

    $state = app(Licensing::class)->heartbeat();

    expect($state->isLive())->toBeTrue();
    expect($state->lastFailure)->toContain('could not be reached');
    // Unchanged: the whole point of grace.
    expect($state->excluded)->toBe(['advanced_reports']);

    // And the last successful contact is *not* moved, or the licence screen
    // would say everything was fine.
    expect($state->lastContactAt?->toIso8601String())
        ->toBe(LicenceState::load()->lastContactAt?->toIso8601String());

    expect(AuditLog::query()->where('action', 'licensing.heartbeat.failed')->exists())->toBeTrue();
});

it('falls back to the unlicensed set once grace is exhausted', function (): void {
    $installationId = app(InstallationIdentity::class)->id();

    config()->set('platform.licensing.grace_days', 0);

    $this->client->nextToken = signToken($this->signingKey, licenceClaims($installationId, [
        'heartbeat_by' => CarbonImmutable::now()->subDay()->toIso8601String(),
    ]));

    app(Licensing::class)->activate('LIC-TEST-0001');

    expect(LicenceState::load()->isLive())->toBeFalse();

    // The vendor mark comes back. Nothing else changes: no screen closes, no
    // order is refused, no service is suspended.
    expect((new LicensedEntitlements)->allows(Feature::RemoveVendorMark->value))->toBeFalse();
});

it('reads a suspended licence as lapsed even before it expires', function (): void {
    $installationId = app(InstallationIdentity::class)->id();

    $this->client->nextToken = signToken($this->signingKey, licenceClaims($installationId, [
        'status' => 'suspended',
    ]));

    app(Licensing::class)->activate('LIC-TEST-0001');

    $state = LicenceState::load();

    expect($state->status)->toBe(LicenceStatus::Suspended);
    expect($state->isLive())->toBeFalse();
});

it('knows when it is inside grace rather than merely live', function (): void {
    $installationId = app(InstallationIdentity::class)->id();

    $this->client->nextToken = signToken($this->signingKey, licenceClaims($installationId, [
        'heartbeat_by' => CarbonImmutable::now()->subDay()->toIso8601String(),
    ]));

    app(Licensing::class)->activate('LIC-TEST-0001');

    $state = LicenceState::load();

    // Still working, and somebody should look before it stops.
    expect($state->isLive())->toBeTrue();
    expect($state->isInGrace())->toBeTrue();
});

// --- entitlements -----------------------------------------------------------

/**
 * A feature the token does not mention is allowed. The alternative — an
 * allow-list — means every feature added after a token was minted is silently
 * switched off for every existing licence, which is a release that breaks
 * paying customers and nobody finds out until they telephone.
 */
it('allows a feature the licence does not exclude', function (): void {
    $installationId = app(InstallationIdentity::class)->id();

    $this->client->nextToken = signToken($this->signingKey, licenceClaims($installationId, [
        'excluded' => ['reseller.white_label'],
    ]));

    app(Licensing::class)->activate('LIC-TEST-0001');

    $entitlements = new LicensedEntitlements;

    expect($entitlements->allows('reseller.white_label'))->toBeFalse();
    expect($entitlements->allows('something.invented.next.year'))->toBeTrue();
    expect($entitlements->limit('max_staff_users'))->toBe(25);
});

/**
 * The dull default, unchanged since Phase 11. An installation nobody licensed
 * must not be crippled by a check it has no way to answer.
 */
it('allows everything on an installation that has never activated a licence', function (): void {
    $entitlements = new LicensedEntitlements;

    expect($entitlements->allows(Feature::RemoveVendorMark->value))->toBeTrue();
    expect($entitlements->allows('anything.at.all'))->toBeTrue();
    expect($entitlements->limit('max_staff_users'))->toBeNull();
});

it('binds the unrestricted entitlements when no licence server is configured', function (): void {
    config()->set('platform.licensing.api_url');

    $this->app->forgetInstance(Entitlements::class);

    // Not `LicensedEntitlements`: an installation with nothing configured
    // never reads a state row at all.
    expect(app(Entitlements::class)->allows('anything'))->toBeTrue();
    expect(app(Entitlements::class))->not->toBeInstanceOf(LicensedEntitlements::class);
});

// --- deactivation -----------------------------------------------------------

/**
 * An operator who has decided this installation is no longer licensed should
 * not be blocked by the vendor being down. The activation the server still
 * thinks is live is the vendor's to reconcile — they can see the missing
 * heartbeats.
 */
it('deactivates locally even when the server cannot be told', function (): void {
    $installationId = app(InstallationIdentity::class)->id();

    $this->client->nextToken = signToken($this->signingKey, licenceClaims($installationId));
    app(Licensing::class)->activate('LIC-TEST-0001');

    $this->client->failWith = LicenceUnreachable::transport('deactivate', 'ConnectionException');

    app(Licensing::class)->deactivate();

    expect(LicenceState::load()->configured)->toBeFalse();
    expect(AuditLog::query()->where('action', 'licensing.deactivated')->exists())->toBeTrue();
    expect(AuditLog::query()->where('action', 'licensing.deactivate.unreachable')->exists())->toBeTrue();
});

it('does nothing on a heartbeat for an installation with no licence', function (): void {
    $state = app(Licensing::class)->heartbeat();

    expect($state->configured)->toBeFalse();
    expect(AuditLog::query()->where('action', 'like', 'licensing.%')->exists())->toBeFalse();
});
