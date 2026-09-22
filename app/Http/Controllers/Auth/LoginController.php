<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Application\Identity\AuthenticateUser;
use App\Http\Controllers\Auth\Concerns\ResolvesGuard;
use App\Http\Controllers\Controller;
use App\Http\Requests\Identity\LoginRequest;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Sign-in for both guards.
 *
 * One controller, parameterised by the route group's guard, so the two
 * surfaces cannot drift apart: a fix to throttling or to the two-factor
 * hand-off lands on both at once.
 */
final class LoginController extends Controller
{
    use ResolvesGuard;

    /**
     * Session key holding a verified-but-not-yet-granted sign-in.
     *
     * No session is created until the second factor clears, so there is no
     * half-authenticated state for the rest of the application to misread.
     */
    public const string PENDING_KEY = 'auth.pending_two_factor';

    public function __construct(private readonly AuthenticateUser $authenticator) {}

    public function create(Request $request): Response|RedirectResponse
    {
        $guard = $this->guard($request);

        if (Auth::guard($guard->value)->check()) {
            return redirect()->to($guard->homePath());
        }

        return Inertia::render('Auth/Login', [
            'guard' => $guard->value,
            'forgotPasswordUrl' => route($guard->routePrefix().'.password.request'),
            'status' => $request->session()->get('status'),
        ]);
    }

    public function store(LoginRequest $request): RedirectResponse
    {
        $guard = $this->guard($request);

        $attempt = $this->authenticator->attempt(
            $guard,
            $request->string('email')->toString(),
            $request->string('password')->toString(),
        );

        if ($attempt->wasThrottled()) {
            throw ValidationException::withMessages([
                'email' => __('identity.auth.throttled', ['seconds' => $attempt->retryAfterSeconds]),
            ]);
        }

        if (! $attempt->successful || ! $attempt->subject instanceof Model) {
            // One message for every failure. Distinguishing "no such account"
            // from "wrong password" hands an attacker a free enumeration
            // oracle, and the real reason is in the login history instead.
            throw ValidationException::withMessages([
                'email' => __('identity.auth.failed'),
            ]);
        }

        if ($attempt->requiresTwoFactor()) {
            $request->session()->put(self::PENDING_KEY, [
                'guard' => $guard->value,
                'id' => (string) $attempt->subject->getKey(),
                'remember' => $request->boolean('remember'),
            ]);

            return to_route($guard->routePrefix().'.two-factor.challenge');
        }

        $this->authenticator->complete($guard, $attempt->subject, $request->boolean('remember'));

        return redirect()->intended($guard->homePath());
    }

    public function destroy(Request $request): RedirectResponse
    {
        $guard = $this->guard($request);

        $this->authenticator->logout($guard);

        return redirect()->to($guard->loginPath());
    }
}
