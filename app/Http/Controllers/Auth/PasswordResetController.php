<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Application\Identity\SessionRegistry;
use App\Http\Controllers\Auth\Concerns\ResolvesGuard;
use App\Http\Controllers\Controller;
use App\Http\Requests\Identity\PasswordResetLinkRequest;
use App\Http\Requests\Identity\PasswordResetRequest;
use App\Support\Audit\Facades\Audit;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\CanResetPassword;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Password reset for both guards.
 *
 * The two brokers have separate token tables, so a staff token can never be
 * redeemed on the client area or the reverse.
 */
final class PasswordResetController extends Controller
{
    use ResolvesGuard;

    public function __construct(private readonly SessionRegistry $sessions) {}

    public function request(Request $request): Response
    {
        return Inertia::render('Auth/ForgotPassword', [
            'guard' => $this->guard($request)->value,
            'status' => $request->session()->get('status'),
        ]);
    }

    public function email(PasswordResetLinkRequest $request): RedirectResponse
    {
        $guard = $this->guard($request);

        Password::broker($guard->passwordBroker())->sendResetLink(
            $request->only('email'),
        );

        // Always the same answer, whether or not the address exists. The
        // alternative tells an attacker which addresses are registered.
        return back()->with('status', __('identity.auth.reset_link_sent'));
    }

    public function edit(Request $request, string $token): Response
    {
        return Inertia::render('Auth/ResetPassword', [
            'guard' => $this->guard($request)->value,
            'token' => $token,
            'email' => $request->string('email')->toString(),
        ]);
    }

    public function update(PasswordResetRequest $request): RedirectResponse
    {
        $guard = $this->guard($request);

        $status = Password::broker($guard->passwordBroker())->reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function (CanResetPassword $subject) use ($request): void {
                $subject->forceFill([
                    'password' => $request->string('password')->toString(),
                    'remember_token' => Str::random(60),
                    'password_changed_at' => CarbonImmutable::now(),
                ])->save();

                if ($subject instanceof Model && $subject instanceof Authenticatable) {
                    // Every other session is destroyed. A reset is the
                    // remedy for a suspected compromise, and leaving the
                    // attacker signed in elsewhere would defeat it.
                    $this->sessions->revokeOthers($subject, $request->session()->getId());

                    Audit::action('identity.password.reset')
                        ->by($subject)
                        ->on($subject)
                        ->write();

                    event(new PasswordReset($subject));
                }
            },
        );

        if ($status !== Password::PasswordReset) {
            throw ValidationException::withMessages([
                'email' => __($status),
            ]);
        }

        return redirect()->to($guard->loginPath())->with('status', __('identity.auth.password_reset'));
    }
}
