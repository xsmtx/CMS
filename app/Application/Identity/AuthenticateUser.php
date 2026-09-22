<?php

declare(strict_types=1);

namespace App\Application\Identity;

use App\Domain\Identity\Guard;
use App\Domain\Identity\LoginFailureReason;
use App\Infrastructure\Identity\Contracts\AuthenticatableAccount;
use App\Support\Audit\Facades\Audit;
use App\Support\Organizations\OrganizationContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use SensitiveParameter;

/**
 * The single place a session is granted.
 *
 * Every path that produces an authenticated session goes through
 * `complete()`: sign-in, the two-factor challenge, and a password reset that
 * signs the user straight in. Session fixation defence, login history, the
 * session registry and the audit record are therefore impossible to forget,
 * because no caller does them itself.
 */
final readonly class AuthenticateUser
{
    public function __construct(
        private LoginThrottle $throttle,
        private RecordLoginAttempt $history,
        private SessionRegistry $sessions,
        private OrganizationContext $organizations,
        private Request $request,
    ) {}

    /**
     * Verify credentials without granting anything.
     *
     * Returns the subject when they are valid and may sign in, or the reason
     * they may not. The caller decides what happens next, which is what lets
     * the two-factor challenge sit between this and `complete()`.
     */
    public function attempt(Guard $guard, string $email, #[SensitiveParameter] string $password): LoginAttempt
    {
        $ip = (string) $this->request->ip();

        if ($this->throttle->tooManyAttempts($guard, $email, $ip)) {
            $this->history->failed($guard, $email, LoginFailureReason::Throttled);

            return LoginAttempt::throttled($this->throttle->secondsUntilRetry($guard, $email, $ip));
        }

        $provider = Auth::createUserProvider($guard->userProvider());
        $subject = $provider?->retrieveByCredentials(['email' => $email]);

        // A miss still runs a hash comparison. Without it the response time
        // tells an attacker which addresses exist.
        if (! $subject instanceof Model || ! $subject instanceof AuthenticatableAccount) {
            Hash::check($password, '$2y$12$'.str_repeat('0', 53));

            return $this->fail($guard, $email, LoginFailureReason::UnknownIdentity, null, $ip);
        }

        if (! Hash::check($password, (string) $subject->getAttribute('password'))) {
            return $this->fail($guard, $email, LoginFailureReason::InvalidPassword, $subject, $ip);
        }

        if (! $subject->canAuthenticate()) {
            return $this->fail($guard, $email, $this->refusalFor($subject), $subject, $ip);
        }

        $this->throttle->clear($guard, $email, $ip);

        return LoginAttempt::valid($subject);
    }

    /**
     * Grant the session.
     */
    public function complete(Guard $guard, Model&AuthenticatableAccount $subject, bool $remember = false): void
    {
        Auth::guard($guard->value)->login($subject, $remember);

        // A fresh identifier on privilege change, so a session id captured
        // before sign-in cannot be replayed afterwards.
        $this->request->session()->regenerate();

        $subject->forceFill(['last_login_at' => CarbonImmutable::now()])->save();

        $this->organizations->set((string) $subject->getAttribute('organization_id'));
        $this->history->succeeded($guard, $subject);
        $this->sessions->register($guard, $subject, $this->request->session()->getId());

        Audit::action('identity.session.started')
            ->by($subject)
            ->on($subject)
            ->withMetadata(['guard' => $guard->value])
            ->write();
    }

    public function logout(Guard $guard): void
    {
        $subject = Auth::guard($guard->value)->user();

        $this->sessions->forget($this->request->session()->getId());

        Auth::guard($guard->value)->logout();
        $this->request->session()->invalidate();
        $this->request->session()->regenerateToken();

        if ($subject instanceof Model) {
            Audit::action('identity.session.ended')
                ->by($subject)
                ->on($subject)
                ->withMetadata(['guard' => $guard->value])
                ->write();
        }
    }

    private function fail(
        Guard $guard,
        string $email,
        LoginFailureReason $reason,
        ?Model $subject,
        string $ip,
    ): LoginAttempt {
        $this->throttle->recordFailure($guard, $email, $ip);
        $this->history->failed($guard, $email, $reason, $subject);

        return LoginAttempt::failed($reason);
    }

    /**
     * The model knows why it may not sign in. A contact whose portal access
     * was withdrawn reports that rather than an account status, so the
     * history explains what actually stopped them.
     */
    private function refusalFor(Model&AuthenticatableAccount $subject): LoginFailureReason
    {
        return $subject->authRefusalReason();
    }
}
