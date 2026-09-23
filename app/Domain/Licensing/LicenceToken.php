<?php

declare(strict_types=1);

namespace App\Domain\Licensing;

use Carbon\CarbonImmutable;

/**
 * What the licence server said, once it has been proved.
 *
 * A value object rather than an array, because every field here is read by
 * something that makes a decision and a typo'd array key is a decision made
 * quietly. Built only by `VerifyLicenceToken` — an unverified token is not a
 * `LicenceToken`, it is a string somebody sent us.
 *
 * **The edition is here and nothing reads it but the licence screen.** The
 * vendor's commercial model needs an edition; the product must never ask what
 * it is (handoff §15). It is carried so an operator can see what they are
 * paying for.
 *
 * `excluded` rather than `included` is the deliberate half. A token lists what
 * a licence *does not* get, so a feature added to the product after the token
 * was minted is allowed rather than silently switched off — the alternative is
 * a release that breaks every paying customer and nobody finds out until they
 * telephone.
 */
final readonly class LicenceToken
{
    /**
     * @param  list<string>  $excluded  features this licence does not include
     * @param  array<string, int>  $limits  numeric entitlements, e.g. max_staff_users
     */
    public function __construct(
        public string $licenceId,
        public string $installationId,
        public string $edition,
        public array $excluded,
        public array $limits,
        public CarbonImmutable $issuedAt,
        public CarbonImmutable $expiresAt,
        /**
         * When the installation must have spoken to the server again.
         *
         * Separate from `expiresAt`: a licence can be valid for a year and
         * still require a weekly heartbeat, which is what makes revocation
         * take effect in days rather than in a year.
         */
        public CarbonImmutable $heartbeatBy,
        public LicenceStatus $status = LicenceStatus::Active,
    ) {}

    public function allows(string $feature): bool
    {
        return ! in_array($feature, $this->excluded, strict: true);
    }

    /**
     * A numeric entitlement, or null when the licence sets no ceiling.
     *
     * Null rather than zero, because zero is a real answer — "no reseller
     * accounts at all" — and a caller that read the two as the same would cap
     * an unlimited licence at nothing.
     */
    public function limit(string $name): ?int
    {
        return $this->limits[$name] ?? null;
    }

    public function hasExpired(?CarbonImmutable $now = null): bool
    {
        return $this->expiresAt->isBefore($now ?? CarbonImmutable::now());
    }

    public function needsHeartbeat(?CarbonImmutable $now = null): bool
    {
        return $this->heartbeatBy->isBefore($now ?? CarbonImmutable::now());
    }

    /**
     * Whether this token still entitles the installation to anything.
     *
     * The status is the server's word and the dates are the token's own; both
     * have to hold. A suspended licence whose expiry has not arrived is still
     * suspended.
     */
    public function isLive(?CarbonImmutable $now = null): bool
    {
        return $this->status === LicenceStatus::Active && ! $this->hasExpired($now);
    }
}
