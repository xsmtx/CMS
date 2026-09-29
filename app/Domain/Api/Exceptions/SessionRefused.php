<?php

declare(strict_types=1);

namespace App\Domain\Api\Exceptions;

use RuntimeException;

/**
 * A device session that did not open, or a refresh that did not rotate.
 *
 * Every reason named separately in code — the rule `PackageRefused` set,
 * because "the refresh failed" would make an expired token and a stolen one
 * look identical in an audit log, and those are the two events somebody is
 * trying to tell apart.
 *
 * **What the caller is told is deliberately not that.** Every one of these
 * reaches the client as the same `unauthenticated`, for the reason
 * `AuthenticateApiToken` gives: a caller learning that a token exists but has
 * been spent knows more than a caller learning nothing. The distinction is
 * for the audit row and the operator, never for the holder.
 */
final class SessionRefused extends RuntimeException
{
    private function __construct(
        string $message,
        private readonly string $reason,
        /**
         * The device to end, for the two refusals that end one.
         *
         * Carried on the exception rather than revoked before it is thrown,
         * because the caller runs the exchange in a transaction: a revocation
         * written inside it is rolled back by the very refusal that asked for
         * it, which is the mechanism silently doing nothing.
         */
        private readonly ?string $deviceId = null,
    ) {
        parent::__construct($message);
    }

    /**
     * The word that goes on the audit row and on the revoked device.
     */
    public function reason(): string
    {
        return $this->reason;
    }

    public function deviceId(): ?string
    {
        return $this->deviceId;
    }

    public static function unknown(): self
    {
        return new self('No refresh token matches what was presented.', 'unknown');
    }

    public static function expired(): self
    {
        return new self('The refresh token has expired.', 'expired');
    }

    /**
     * The one that revokes the device rather than just refusing the call.
     */
    public static function reused(string $deviceId): self
    {
        return new self('A refresh token was presented twice.', 'reused', $deviceId);
    }

    public static function deviceRevoked(): self
    {
        return new self('The device has been revoked.', 'device_revoked');
    }

    public static function ownerUnavailable(string $deviceId): self
    {
        return new self(
            'The account this device belongs to can no longer sign in.',
            'owner_unavailable',
            $deviceId,
        );
    }
}
