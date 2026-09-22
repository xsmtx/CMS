<?php

declare(strict_types=1);

namespace App\Domain\Identity\Contracts;

use App\Domain\Identity\AccountStatus;
use App\Domain\Identity\Guard;
use App\Domain\Identity\LoginFailureReason;

/**
 * Anything that can sign in.
 *
 * Staff and contacts are separate tables with separate guards, but the
 * authentication services work against this contract rather than either
 * model. That is what lets sign-in, the two-factor challenge and session
 * management be written once, and it replaces the `method_exists` sniffing
 * that an untyped service would otherwise need.
 */
interface PlatformAccount
{
    public function authGuard(): Guard;

    public function accountStatus(): AccountStatus;

    /**
     * The name a human uses for this account.
     */
    public function displayName(): string;

    public function canAuthenticate(): bool;

    /**
     * Why this account may not sign in. Recorded in login history, never
     * shown to the caller.
     */
    public function authRefusalReason(): LoginFailureReason;

    public function hasTwoFactorEnabled(): bool;

    public function hasPendingTwoFactorSetup(): bool;

    /**
     * @return list<string>
     */
    public function twoFactorRecoveryCodeHashes(): array;

    /**
     * Consume a recovery code. The code is removed rather than marked, so a
     * replay cannot succeed.
     */
    public function consumeRecoveryCode(string $candidate): bool;

    /**
     * Generate a fresh set, returning the plain codes once. Only hashes are
     * persisted.
     *
     * @return list<string>
     */
    public function regenerateRecoveryCodes(): array;

    public function disableTwoFactor(): void;
}
