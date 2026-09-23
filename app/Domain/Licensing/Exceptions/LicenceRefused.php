<?php

declare(strict_types=1);

namespace App\Domain\Licensing\Exceptions;

use RuntimeException;

/**
 * A token the installation will not accept.
 *
 * Every one of these is a security event rather than a configuration
 * problem, which is why each has its own constructor: the audit record wants
 * to say *which* of them happened, and "licence invalid" tells an incident
 * review nothing.
 *
 * A refusal is never repaired. An installation that fell back to the last
 * known-good token after a signature failure would be an installation that
 * could be pinned to a revoked licence by anybody able to replay one captured
 * response.
 */
final class LicenceRefused extends RuntimeException
{
    public static function badSignature(): self
    {
        return new self('The licence token was not signed by this vendor.');
    }

    public static function malformed(string $why): self
    {
        return new self("The licence token could not be read: {$why}.");
    }

    public static function forAnotherInstallation(): self
    {
        return new self('The licence token was issued to a different installation.');
    }

    /**
     * A token dated in the future.
     *
     * Either the clocks disagree or somebody is constructing tokens. Both are
     * worth a record, and neither is worth accepting.
     */
    public static function issuedInTheFuture(): self
    {
        return new self('The licence token is dated in the future.');
    }

    /**
     * A token older than the one already held.
     *
     * This is the replay. A captured response from before a downgrade or a
     * revocation is a valid, correctly signed token — the only thing wrong
     * with it is that the installation has already seen a newer one.
     */
    public static function replayed(): self
    {
        return new self('The licence token is older than the one this installation already holds.');
    }

    public static function noPublicKey(): self
    {
        return new self('This distribution has no licence public key, so no token can be verified.');
    }
}
