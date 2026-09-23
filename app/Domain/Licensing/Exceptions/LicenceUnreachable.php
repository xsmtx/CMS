<?php

declare(strict_types=1);

namespace App\Domain\Licensing\Exceptions;

use RuntimeException;

/**
 * The licence server did not answer.
 *
 * Deliberately a different type from `LicenceRefused`, and the distinction is
 * the most important one in this context: **"the vendor is broken" and "your
 * licence is revoked" must never be the same outcome.** One means try again in
 * a minute and keep working; the other means the entitlements change. A client
 * that collapsed them would turn a vendor's DNS failure into a customer's
 * outage, which is the failure ADR 0013's grace period exists to prevent.
 *
 * None of these carries a response body. A vendor's error page in an
 * operator's flash message is a vendor's stack trace in a screenshot.
 */
final class LicenceUnreachable extends RuntimeException
{
    public static function transport(string $endpoint, string $kind): self
    {
        return new self("The licence server could not be reached for [{$endpoint}] ({$kind}).");
    }

    public static function status(string $endpoint, int $status): self
    {
        return new self("The licence server answered [{$endpoint}] with HTTP {$status}.");
    }

    /**
     * A 200 with nothing in it.
     *
     * Counted as unreachable rather than as a refusal, for the reason above: a
     * licence server that answered without answering has not said anything
     * about the licence.
     */
    public static function noToken(string $endpoint): self
    {
        return new self("The licence server answered [{$endpoint}] without a token.");
    }

    public static function notConfigured(): self
    {
        return new self('This installation has no licence server configured.');
    }
}
