<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Auth\Concerns\ResolvesGuard;
use App\Http\Controllers\Controller;
use App\Http\Middleware\RequireRecentAuthentication;
use App\Support\Audit\Facades\Audit;
use App\Support\Identity\CurrentActor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * "It is still you, isn't it."
 *
 * One controller for both guards, parameterised by the route group, so the two
 * surfaces cannot drift — the same reason `LoginController` is one.
 *
 * Three things this does that a naive version would not:
 *
 * - **It is rate limited, hard.** Five attempts per minute per account. A
 *   confirmation form is a password oracle against an already-authenticated
 *   session, which is exactly what somebody with a stolen cookie wants; without
 *   a limiter it would be a better brute-force target than the sign-in screen,
 *   because it leaks the account name for free.
 * - **A wrong password is audited.** Somebody failing this is either an operator
 *   who mistyped or a session that is not theirs, and the second is the kind of
 *   thing an incident review needs to be able to find.
 * - **It returns them where they were going.** A confirmation that dropped
 *   somebody on a dashboard after they pressed Terminate is a confirmation they
 *   have to go and find the button again after, and they will start leaving the
 *   window open instead.
 */
final class ConfirmPasswordController extends Controller
{
    use ResolvesGuard;

    public function __construct(private readonly CurrentActor $actor) {}

    public function create(Request $request): Response
    {
        $guard = $this->guard($request);

        return Inertia::render('Auth/ConfirmPassword', [
            'guard' => $guard->value,
            // What they were about to do, so the screen can say so rather than
            // asking for a password out of nowhere.
            'intended' => $request->session()->get('auth.intended'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $guard = $this->guard($request);

        $data = $request->validate(['password' => ['required', 'string']]);

        // Through `CurrentActor` rather than `Auth::user()`, which resolves
        // only the default guard — the mistake this platform keeps a helper to
        // stop, since the admin area is not the default one.
        $user = $this->actor->model();

        if ($user === null) {
            // The session expired while the form was open. Sign-in, not an
            // error: they are going to have to do that anyway.
            return to_route($guard->routePrefix().'.login');
        }

        $key = 'password-confirm:'.$guard->value.':'.$user->getKey();

        // A confirmation form is a password oracle against a session somebody
        // may already have stolen. Five a minute.
        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw ValidationException::withMessages([
                'password' => __('identity.auth.throttled', [
                    'seconds' => RateLimiter::availableIn($key),
                ]),
            ]);
        }

        if (! Hash::check($data['password'], (string) $user->getAttribute('password'))) {
            RateLimiter::hit($key, 60);

            // Either a mistype or a session that is not theirs, and the second
            // is what an incident review has to be able to find.
            Audit::action('identity.password.confirm_failed')
                ->by($user)
                ->withMetadata(['guard' => $guard->value])
                ->write();

            throw ValidationException::withMessages([
                'password' => __('identity.auth.confirm_wrong_password'),
            ]);
        }

        RateLimiter::clear($key);

        $request->session()->put(RequireRecentAuthentication::SESSION_KEY, time());

        Audit::action('identity.password.confirmed')
            ->by($user)
            ->withMetadata(['guard' => $guard->value])
            ->write();

        // Back where they were going. A confirmation that dropped somebody on a
        // dashboard is one they route around by leaving the window open.
        $intended = $request->session()->pull('auth.intended');

        return is_string($intended) && $intended !== ''
            ? redirect()->to($intended)
            : redirect()->to($guard->homePath());
    }
}
