<?php

declare(strict_types=1);

namespace App\Infrastructure\Identity\Concerns;

use App\Domain\Identity\AccountStatus;
use App\Domain\Identity\Guard;
use App\Infrastructure\Identity\Models\AuthenticatedSession;
use App\Infrastructure\Identity\Models\LoginHistory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Behaviour shared by both authenticatables.
 *
 * Staff and contacts differ in what they can reach, not in how they prove
 * who they are. Keeping the proof in one place means a fix to the recovery
 * code logic cannot land on one guard and miss the other.
 *
 * @mixin Model
 */
trait Authenticates
{
    /**
     * How many single-use codes a user gets when two-factor is enabled.
     */
    public const int RECOVERY_CODE_COUNT = 8;

    abstract public function authGuard(): Guard;

    abstract public function accountStatus(): AccountStatus;

    abstract public function displayName(): string;

    /**
     * @return MorphMany<LoginHistory, $this>
     */
    public function loginHistories(): MorphMany
    {
        return $this->morphMany(LoginHistory::class, 'subject')->latest('occurred_at');
    }

    /**
     * @return MorphMany<AuthenticatedSession, $this>
     */
    public function authenticatedSessions(): MorphMany
    {
        return $this->morphMany(AuthenticatedSession::class, 'subject')->latest('last_active_at');
    }

    /**
     * Two-factor only counts once the user has proved they can read a code
     * from their authenticator. An unconfirmed secret protects nothing and
     * would lock out anyone whose setup silently failed.
     */
    public function hasTwoFactorEnabled(): bool
    {
        return $this->getAttribute('two_factor_secret') !== null
            && $this->getAttribute('two_factor_confirmed_at') !== null;
    }

    public function hasPendingTwoFactorSetup(): bool
    {
        return $this->getAttribute('two_factor_secret') !== null
            && $this->getAttribute('two_factor_confirmed_at') === null;
    }

    /**
     * Recovery codes are stored hashed, exactly like passwords. A database
     * disclosure must not hand over a working second factor.
     *
     * @return list<string>
     */
    public function twoFactorRecoveryCodeHashes(): array
    {
        /** @var list<string>|null $codes */
        $codes = $this->getAttribute('two_factor_recovery_codes');

        return $codes ?? [];
    }

    /**
     * Consume a recovery code. Returns false when it does not match any
     * unused code, and the used code is removed rather than marked, so a
     * replay cannot succeed.
     */
    public function consumeRecoveryCode(string $candidate): bool
    {
        $remaining = [];
        $matched = false;

        foreach ($this->twoFactorRecoveryCodeHashes() as $hash) {
            if (! $matched && Hash::check($candidate, $hash)) {
                $matched = true;

                continue;
            }

            $remaining[] = $hash;
        }

        if ($matched) {
            $this->forceFill(['two_factor_recovery_codes' => $remaining])->save();
        }

        return $matched;
    }

    /**
     * Generate a fresh set. The plain codes are returned once, to be shown
     * to the user, and only the hashes are persisted.
     *
     * @return list<string>
     */
    public function regenerateRecoveryCodes(): array
    {
        $plain = [];
        $hashed = [];

        for ($i = 0; $i < self::RECOVERY_CODE_COUNT; $i++) {
            $code = Str::lower(Str::random(5).'-'.Str::random(5));
            $plain[] = $code;
            $hashed[] = Hash::make($code);
        }

        $this->forceFill(['two_factor_recovery_codes' => $hashed])->save();

        return $plain;
    }

    public function disableTwoFactor(): void
    {
        $this->forceFill([
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
        ])->save();
    }

    public function canAuthenticate(): bool
    {
        return $this->accountStatus()->canAuthenticate();
    }
}
