<?php

declare(strict_types=1);

namespace App\Support\Http\Exceptions;

use RuntimeException;

/**
 * A URL this platform will not make a request to.
 *
 * **No message echoes the URL.** The URL is the thing somebody is trying to
 * smuggle, and a refusal that quoted it would put
 * `http://169.254.169.254/latest/meta-data/iam/` into a log line, a flash
 * message and eventually a screenshot in a support ticket. The reason is enough
 * to fix a legitimate mistake and tells an attacker nothing they did not already
 * know.
 */
final class UnsafeUrl extends RuntimeException
{
    public static function malformed(): self
    {
        return new self('That is not a URL this platform can make a request to.');
    }

    public static function scheme(string $scheme): self
    {
        return new self("Only http and https are allowed; [{$scheme}] is not.");
    }

    public static function credentials(): self
    {
        return new self('A URL may not carry a username or a password.');
    }

    public static function port(int $port): self
    {
        return new self("Port {$port} is not one this platform will connect to.");
    }

    /**
     * The one that matters.
     *
     * Loopback, link-local, private ranges — and the cloud metadata endpoint,
     * which lives at a link-local address and hands out credentials to anybody
     * who asks from inside the instance.
     */
    public static function privateAddress(): self
    {
        return new self('That address is not on the public internet.');
    }

    /**
     * Kept, and deliberately not used by `SafeUrl`.
     *
     * A host that does not resolve is a transient network problem rather than an
     * attack, and refusing it there would turn a customer's ten-minute DNS
     * outage into webhook deliveries permanently marked unsafe. It stays here
     * for a caller that genuinely needs "prove it resolves before I start" —
     * and the reason it is not the default is written down so nobody wires it in
     * thinking it was an oversight.
     */
    public static function unresolvable(): self
    {
        return new self('That host does not resolve to an address.');
    }
}
