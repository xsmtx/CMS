<?php

declare(strict_types=1);

namespace App\Application\Identity;

use App\Domain\Identity\LoginFailureReason;
use App\Infrastructure\Identity\Contracts\AuthenticatableAccount;
use Illuminate\Database\Eloquent\Model;

/**
 * Outcome of verifying credentials.
 *
 * Deliberately not a boolean. The caller needs to distinguish "grant a
 * session now" from "ask for the second factor first", and an operator needs
 * the refusal reason in the history even though the user never sees it.
 */
final readonly class LoginAttempt
{
    private function __construct(
        public bool $successful,
        public (Model&AuthenticatableAccount)|null $subject = null,
        public ?LoginFailureReason $reason = null,
        public int $retryAfterSeconds = 0,
    ) {}

    public static function valid(Model&AuthenticatableAccount $subject): self
    {
        return new self(true, $subject);
    }

    public static function failed(LoginFailureReason $reason): self
    {
        return new self(false, reason: $reason);
    }

    public static function throttled(int $retryAfterSeconds): self
    {
        return new self(false, reason: LoginFailureReason::Throttled, retryAfterSeconds: $retryAfterSeconds);
    }

    public function wasThrottled(): bool
    {
        return $this->reason === LoginFailureReason::Throttled;
    }

    /**
     * Whether the subject must clear a second factor before the session is
     * granted.
     */
    public function requiresTwoFactor(): bool
    {
        return $this->successful
            && $this->subject instanceof AuthenticatableAccount
            && $this->subject->hasTwoFactorEnabled();
    }
}
