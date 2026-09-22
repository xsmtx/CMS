<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Application\Identity\AuthenticateUser;
use App\Application\Identity\RecordLoginAttempt;
use App\Application\Identity\TwoFactorAuthenticator;
use App\Domain\Identity\Guard;
use App\Domain\Identity\LoginFailureReason;
use App\Http\Controllers\Auth\Concerns\ResolvesGuard;
use App\Http\Controllers\Controller;
use App\Http\Requests\Identity\TwoFactorChallengeRequest;
use App\Infrastructure\Identity\Contracts\AuthenticatableAccount;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The second factor, sitting between verified credentials and a granted
 * session.
 *
 * The pending sign-in lives in the session and carries only an identifier,
 * so a stolen session at this point is worth nothing without the code.
 */
final class TwoFactorChallengeController extends Controller
{
    use ResolvesGuard;

    public function __construct(
        private readonly AuthenticateUser $authenticator,
        private readonly TwoFactorAuthenticator $twoFactor,
        private readonly RecordLoginAttempt $history,
    ) {}

    public function create(Request $request): Response|RedirectResponse
    {
        $guard = $this->guard($request);

        if ($this->pendingSubject($request, $guard) === null) {
            return redirect()->to($guard->loginPath());
        }

        return Inertia::render('Auth/TwoFactorChallenge', [
            'guard' => $guard->value,
        ]);
    }

    public function store(TwoFactorChallengeRequest $request): RedirectResponse
    {
        $guard = $this->guard($request);
        $subject = $this->pendingSubject($request, $guard);

        if ($subject === null) {
            return redirect()->to($guard->loginPath());
        }

        // Its own limiter: six digits is a small search space, and a
        // mistyped code must not consume the password budget.
        $limiterKey = 'two-factor:'.$request->session()->getId();

        if (RateLimiter::tooManyAttempts($limiterKey, 5)) {
            throw ValidationException::withMessages([
                'code' => __('identity.auth.throttled', ['seconds' => RateLimiter::availableIn($limiterKey)]),
            ]);
        }

        $isRecovery = $request->boolean('recovery');
        $code = $request->string('code')->toString();

        if (! $this->twoFactor->challenge($subject, $code, $isRecovery)) {
            RateLimiter::hit($limiterKey, 60);

            $this->history->failed(
                $guard,
                (string) $subject->getAttribute('email'),
                $isRecovery ? LoginFailureReason::InvalidRecoveryCode : LoginFailureReason::InvalidTwoFactorCode,
                $subject,
            );

            throw ValidationException::withMessages([
                'code' => __($isRecovery ? 'identity.auth.invalid_recovery_code' : 'identity.auth.invalid_code'),
            ]);
        }

        RateLimiter::clear($limiterKey);

        $pending = $this->pending($request);
        $request->session()->forget(LoginController::PENDING_KEY);

        $this->authenticator->complete($guard, $subject, (bool) ($pending['remember'] ?? false));

        return redirect()->intended($guard->homePath());
    }

    /**
     * @return array<string, mixed>|null
     */
    private function pending(Request $request): ?array
    {
        $pending = $request->session()->get(LoginController::PENDING_KEY);

        return is_array($pending) ? $pending : null;
    }

    private function pendingSubject(Request $request, Guard $guard): (Model&AuthenticatableAccount)|null
    {
        $pending = $this->pending($request);

        // The guard is re-checked against the route: a pending client
        // sign-in must not be completable on the admin challenge screen.
        if ($pending === null || ($pending['guard'] ?? null) !== $guard->value) {
            return null;
        }

        $subject = Auth::createUserProvider($guard->userProvider())?->retrieveById($pending['id'] ?? '');

        return $subject instanceof Model
            && $subject instanceof AuthenticatableAccount
            && $subject->hasTwoFactorEnabled()
                ? $subject
                : null;
    }
}
