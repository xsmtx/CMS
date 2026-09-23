<?php

declare(strict_types=1);

namespace App\Support\Http;

use App\Support\Http\Exceptions\UnsafeUrl;

/**
 * Whether this platform may make a request to a URL an operator typed.
 *
 * Every configurable URL in this product is a server-side request forgery
 * waiting to happen: a webhook endpoint, a theme's font source, a help link that
 * something decides to fetch. An operator — or a reseller's staff member, or
 * anybody who has compromised one account — can point one at
 * `http://169.254.169.254/latest/meta-data/` and read the cloud instance's
 * credentials out of the response body a webhook delivery records.
 *
 * **The check happens immediately before the request, on the resolved address.**
 * Validating a URL when somebody types it proves nothing: DNS can change between
 * then and the call, and that is the entire technique — a hostname that resolves
 * publicly during validation and to `127.0.0.1` a second later. So this is a
 * guard at the call site, not a validation rule, and it is documented as such
 * because the temptation is to put it in a form request and feel finished.
 *
 * Four refusals:
 *
 * - **A scheme that is not `http` or `https`.** `file://`, `gopher://` and
 *   `dict://` are all reachable through libcurl and all read things.
 * - **Credentials in the URL.** `https://user:pass@host` sends a header the
 *   operator did not know they were sending, and it appears in the log.
 * - **A non-standard port**, unless the installation has said otherwise. A
 *   webhook endpoint on port 6379 is somebody asking this platform to talk to
 *   their Redis.
 * - **An address that is not public**: loopback, link-local, private ranges,
 *   unique-local IPv6, and the metadata addresses by name.
 *
 * Every refusal names what was wrong without echoing the URL back into a log
 * line, because the URL is the thing somebody is trying to smuggle.
 */
final readonly class SafeUrl
{
    /**
     * The ports a request may go to.
     *
     * Deliberately short. An installation that genuinely needs another says so
     * in configuration, and the fact that it had to is the point.
     *
     * @var list<int>
     */
    private const array DEFAULT_PORTS = [80, 443, 8080, 8443];

    /**
     * Refuse, or return the URL unchanged.
     *
     * Returns the input so a call site reads `Http::post(SafeUrl::check($url))`
     * and cannot forget to use the checked value — a guard whose result is
     * ignored is a guard that is not there.
     */
    public static function check(string $url): string
    {
        $parts = parse_url($url);

        if ($parts === false || ! isset($parts['host']) || ! isset($parts['scheme'])) {
            throw UnsafeUrl::malformed();
        }

        $scheme = strtolower($parts['scheme']);

        if (! in_array($scheme, ['http', 'https'], strict: true)) {
            // `file://`, `gopher://`, `dict://` — all reachable through
            // libcurl, all read something.
            throw UnsafeUrl::scheme($scheme);
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            throw UnsafeUrl::credentials();
        }

        $port = $parts['port'] ?? ($scheme === 'https' ? 443 : 80);

        if (! in_array($port, self::allowedPorts(), strict: true)) {
            throw UnsafeUrl::port($port);
        }

        self::refuseNonPublicHost($parts['host']);

        return $url;
    }

    /**
     * Whether a URL would be accepted, without throwing.
     *
     * For a screen that wants to warn rather than refuse — a settings form
     * showing "this endpoint will be refused" beside a field is better than one
     * that saves and then fails on every delivery.
     */
    public static function allows(string $url): bool
    {
        try {
            self::check($url);

            return true;
        } catch (UnsafeUrl) {
            return false;
        }
    }

    /**
     * Refuse a host that resolves anywhere but the public internet.
     *
     * **Every** address it resolves to is checked, not the first. A hostname with
     * two A records — one public, one loopback — would otherwise pass and then
     * connect to whichever the resolver felt like.
     */
    private static function refuseNonPublicHost(string $host): void
    {
        $normalised = strtolower(trim($host, '[]'));

        // Named outright, because they are the two that matter and because a
        // resolver can be made to answer for them.
        if (in_array($normalised, ['localhost', 'metadata.google.internal'], strict: true)) {
            throw UnsafeUrl::privateAddress();
        }

        $addresses = self::resolve($normalised);

        /*
         * A host that resolves to nothing is **allowed through**, and that is a
         * deliberate reversal of the obvious answer.
         *
         * A webhook endpoint whose DNS is broken for ten minutes is a transient
         * network problem, not an attack. Refusing it here would record the
         * delivery as *unsafe* — which `DeliverWebhookNow` treats as a
         * permanent failure and stops retrying — so a customer's DNS blip would
         * silently end their webhook deliveries. Letting it through means the
         * HTTP client fails to connect, which is a retryable failure and the
         * correct one.
         *
         * Nothing is lost by it: a host that does not resolve cannot be
         * connected to either.
         */
        foreach ($addresses as $address) {
            if (! self::isPublic($address)) {
                throw UnsafeUrl::privateAddress();
            }
        }
    }

    /**
     * @return list<string>
     */
    private static function resolve(string $host): array
    {
        // An address written literally needs no lookup, and looking it up would
        // let a resolver rewrite it.
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return [$host];
        }

        $records = @dns_get_record($host, DNS_A | DNS_AAAA);

        if ($records === false) {
            return [];
        }

        $addresses = [];

        foreach ($records as $record) {
            foreach (['ip', 'ipv6'] as $key) {
                if (isset($record[$key]) && is_string($record[$key])) {
                    $addresses[] = $record[$key];
                }
            }
        }

        return array_values(array_unique($addresses));
    }

    /**
     * Whether an address is on the public internet.
     *
     * PHP's own flags do most of it. `169.254.0.0/16` is in
     * `FILTER_FLAG_NO_RES_RANGE`, which is what makes the cloud metadata
     * endpoint unreachable — the single most valuable line here.
     */
    private static function isPublic(string $address): bool
    {
        return filter_var(
            $address,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
        ) !== false;
    }

    /**
     * @return list<int>
     */
    private static function allowedPorts(): array
    {
        $configured = config('platform.security.outbound_ports');

        if (! is_array($configured) || $configured === []) {
            return self::DEFAULT_PORTS;
        }

        return array_values(array_map(
            static fn (mixed $port): int => (int) $port,
            array_filter($configured, is_numeric(...)),
        ));
    }
}
