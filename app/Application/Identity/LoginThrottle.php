<?php

declare(strict_types=1);

namespace App\Application\Identity;

use App\Domain\Identity\Guard;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/**
 * Sign-in rate limiting.
 *
 * Two buckets, because they defend against different things. The per-identity
 * bucket stops an attacker grinding one account's password. The per-address
 * bucket stops one host spraying a common password across many accounts,
 * which the first bucket would never notice.
 *
 * Buckets are keyed per guard so that hammering the client area cannot lock
 * staff out of the admin panel during an incident.
 */
final class LoginThrottle
{
    private const int MAX_ATTEMPTS_PER_IDENTITY = 5;

    private const int MAX_ATTEMPTS_PER_ADDRESS = 30;

    private const int DECAY_SECONDS = 300;

    public function tooManyAttempts(Guard $guard, string $email, string $ipAddress): bool
    {
        return RateLimiter::tooManyAttempts($this->identityKey($guard, $email, $ipAddress), self::MAX_ATTEMPTS_PER_IDENTITY)
            || RateLimiter::tooManyAttempts($this->addressKey($guard, $ipAddress), self::MAX_ATTEMPTS_PER_ADDRESS);
    }

    public function recordFailure(Guard $guard, string $email, string $ipAddress): void
    {
        RateLimiter::hit($this->identityKey($guard, $email, $ipAddress), self::DECAY_SECONDS);
        RateLimiter::hit($this->addressKey($guard, $ipAddress), self::DECAY_SECONDS);
    }

    /**
     * A success clears the identity bucket but deliberately leaves the
     * address bucket alone: one correct guess in a spray is not evidence
     * that the host is friendly.
     */
    public function clear(Guard $guard, string $email, string $ipAddress): void
    {
        RateLimiter::clear($this->identityKey($guard, $email, $ipAddress));
    }

    public function secondsUntilRetry(Guard $guard, string $email, string $ipAddress): int
    {
        return max(
            RateLimiter::availableIn($this->identityKey($guard, $email, $ipAddress)),
            RateLimiter::availableIn($this->addressKey($guard, $ipAddress)),
        );
    }

    /**
     * Limiter used by the two-factor challenge. Separate from sign-in so a
     * mistyped code cannot consume the password budget, and much tighter,
     * because six digits is a small search space.
     */
    public function twoFactorLimit(string $sessionId): Limit
    {
        return Limit::perMinute(5)->by('two-factor:'.$sessionId);
    }

    private function identityKey(Guard $guard, string $email, string $ipAddress): string
    {
        return 'login:'.$guard->value.':'.Str::lower($email).'|'.$ipAddress;
    }

    private function addressKey(Guard $guard, string $ipAddress): string
    {
        return 'login-ip:'.$guard->value.':'.$ipAddress;
    }
}
