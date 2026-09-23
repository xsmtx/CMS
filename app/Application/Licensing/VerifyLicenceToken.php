<?php

declare(strict_types=1);

namespace App\Application\Licensing;

use App\Domain\Licensing\Exceptions\LicenceRefused;
use App\Domain\Licensing\LicenceStatus;
use App\Domain\Licensing\LicenceToken;
use Carbon\CarbonImmutable;
use SodiumException;
use Throwable;

/**
 * Proving a licence, locally.
 *
 * The one security property this phase has: the installation verifies the
 * token with a public key shipped in the distribution, and the private key
 * exists only on vendor infrastructure (ADR 0013). The installation can prove
 * a licence is valid. It cannot mint one.
 *
 * Ed25519 through libsodium, which is in PHP's core — no dependency, no
 * configuration, and no algorithm negotiation. A token that says which
 * algorithm to use is a token that can say "none".
 *
 * The wire format is `base64url(payload).base64url(signature)`, and the
 * signature covers the **raw payload bytes** rather than the decoded JSON:
 * two JSON encoders disagree about key order and whitespace, and a signature
 * over re-encoded JSON is a signature that fails on a different PHP version.
 *
 * Four refusals, and each is audited by the caller rather than swallowed here:
 *
 * - a signature that is not this vendor's;
 * - a token addressed to a different installation;
 * - a token dated in the future — the clocks disagree, or somebody is
 *   constructing tokens;
 * - a token older than the one already held, which is the replay.
 *
 * The last one is why `previouslyIssuedAt` is a parameter. A captured response
 * from before a downgrade is a correctly signed, unexpired, correctly
 * addressed token; the only thing wrong with it is that this installation has
 * already seen a newer one.
 */
final readonly class VerifyLicenceToken
{
    /** A minute of slack, because two correct clocks still differ. */
    private const int CLOCK_SKEW_SECONDS = 60;

    public function __construct(private LicencePublicKey $publicKey) {}

    /**
     * @param  string  $token  the wire form, as the server returned it
     * @param  string  $installationId  who this installation says it is
     * @param  CarbonImmutable|null  $previouslyIssuedAt  the newest token already held
     */
    public function handle(
        string $token,
        string $installationId,
        ?CarbonImmutable $previouslyIssuedAt = null,
        ?CarbonImmutable $now = null,
    ): LicenceToken {
        $key = $this->publicKey->bytes();

        if ($key === null) {
            // A distribution with no key cannot verify anything, and a
            // distribution that pretended otherwise would be accepting
            // whatever it was sent.
            throw LicenceRefused::noPublicKey();
        }

        [$payloadRaw, $signature] = $this->split($token);

        if (! $this->signatureHolds($signature, $payloadRaw, $key)) {
            throw LicenceRefused::badSignature();
        }

        $claims = $this->claims($payloadRaw);
        $now ??= CarbonImmutable::now();

        $issuedAt = $this->date($claims, 'issued_at');

        if ($issuedAt->isAfter($now->addSeconds(self::CLOCK_SKEW_SECONDS))) {
            throw LicenceRefused::issuedInTheFuture();
        }

        if ($previouslyIssuedAt !== null && $issuedAt->isBefore($previouslyIssuedAt)) {
            throw LicenceRefused::replayed();
        }

        if (($claims['installation_id'] ?? null) !== $installationId) {
            throw LicenceRefused::forAnotherInstallation();
        }

        return new LicenceToken(
            licenceId: $this->string($claims, 'licence_id'),
            installationId: $installationId,
            edition: $this->string($claims, 'edition'),
            excluded: $this->strings($claims, 'excluded'),
            limits: $this->limits($claims),
            issuedAt: $issuedAt,
            expiresAt: $this->date($claims, 'expires_at'),
            heartbeatBy: $this->date($claims, 'heartbeat_by'),
            status: LicenceStatus::tryFrom($this->string($claims, 'status')) ?? LicenceStatus::Revoked,
        );
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function split(string $token): array
    {
        $parts = explode('.', trim($token));

        if (count($parts) !== 2) {
            throw LicenceRefused::malformed('it is not two dot-separated parts');
        }

        $payload = $this->decode($parts[0]);
        $signature = $this->decode($parts[1]);

        if ($payload === null || $signature === null) {
            throw LicenceRefused::malformed('one of its parts is not base64url');
        }

        return [$payload, $signature];
    }

    /**
     * Base64url, without padding, and strict.
     *
     * Strict because a decoder that ignores stray characters will happily
     * decode two different strings to the same bytes, and a signature check
     * over "whatever that decoded to" is not a signature check.
     */
    private function decode(string $value): ?string
    {
        $padded = strtr($value, '-_', '+/');
        $padded .= str_repeat('=', (4 - strlen($padded) % 4) % 4);

        $decoded = base64_decode($padded, true);

        return $decoded === false ? null : $decoded;
    }

    private function signatureHolds(string $signature, string $payload, string $key): bool
    {
        // An empty signature, payload or key is not a failed check, it is a
        // malformed token — and libsodium refuses the empty string outright.
        if ($signature === '' || $payload === '' || $key === '') {
            return false;
        }

        try {
            return sodium_crypto_sign_verify_detached($signature, $payload, $key);
        } catch (SodiumException) {
            // A signature or key of the wrong length. Not a licence.
            return false;
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function claims(string $payload): array
    {
        try {
            $claims = json_decode($payload, true, 32, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            throw LicenceRefused::malformed('its payload is not JSON');
        }

        if (! is_array($claims)) {
            throw LicenceRefused::malformed('its payload is not an object');
        }

        /** @var array<string, mixed> $claims */
        return $claims;
    }

    /**
     * @param  array<string, mixed>  $claims
     */
    private function string(array $claims, string $key): string
    {
        $value = $claims[$key] ?? null;

        if (! is_string($value) || $value === '') {
            throw LicenceRefused::malformed("it has no {$key}");
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $claims
     */
    private function date(array $claims, string $key): CarbonImmutable
    {
        try {
            return CarbonImmutable::parse($this->string($claims, $key));
        } catch (Throwable) {
            throw LicenceRefused::malformed("its {$key} is not a date");
        }
    }

    /**
     * @param  array<string, mixed>  $claims
     * @return list<string>
     */
    private function strings(array $claims, string $key): array
    {
        $value = $claims[$key] ?? [];

        if (! is_array($value)) {
            throw LicenceRefused::malformed("its {$key} is not a list");
        }

        return array_values(array_filter(
            array_map(static fn (mixed $entry): string => is_string($entry) ? $entry : '', $value),
            static fn (string $entry): bool => $entry !== '',
        ));
    }

    /**
     * @param  array<string, mixed>  $claims
     * @return array<string, int>
     */
    private function limits(array $claims): array
    {
        $value = $claims['limits'] ?? [];

        if (! is_array($value)) {
            throw LicenceRefused::malformed('its limits are not an object');
        }

        $limits = [];

        foreach ($value as $name => $limit) {
            if (is_string($name) && is_int($limit)) {
                $limits[$name] = $limit;
            }
        }

        return $limits;
    }
}
