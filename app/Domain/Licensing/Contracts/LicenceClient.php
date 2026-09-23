<?php

declare(strict_types=1);

namespace App\Domain\Licensing\Contracts;

/**
 * The vendor's licence API, as this installation sees it.
 *
 * A contract in `Domain` and an implementation in `Infrastructure`, like every
 * other remote system here. What it returns is the **wire form of a token** —
 * a string — and never a decision: the installation proves the token itself
 * with the embedded public key, so a client that returned "valid: true" would
 * be a client an attacker could replace with one that always says so.
 *
 * Every method either returns a token or throws. A network failure is a
 * throw, not a `null` that a caller can mistake for "revoked": one of those
 * means "try again in a minute" and the other means "stop", and collapsing
 * them is how a vendor's DNS outage becomes a customer's outage.
 */
interface LicenceClient
{
    /**
     * Claim this licence for this installation.
     *
     * @param  array<string, string>  $claims  normalised environment claims
     * @return string the signed token, in wire form
     */
    public function activate(string $licenceKey, string $installationId, array $claims): string;

    /**
     * Say we are still here, and get a fresh token.
     *
     * @param  array<string, string>  $claims
     */
    public function heartbeat(string $licenceKey, string $installationId, array $claims): string;

    /**
     * Give the activation back.
     *
     * Returns nothing: there is no token for an installation that has just
     * stopped being licensed.
     */
    public function deactivate(string $licenceKey, string $installationId): void;
}
