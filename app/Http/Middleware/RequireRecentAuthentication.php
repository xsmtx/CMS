<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A password, again, before something that cannot be undone.
 *
 * This closes the gap `AppConfirm` has been documenting since Phase 11: §8's
 * confirmation ladder ends with "step-up auth as policy requires", and until now
 * the component said plainly that this installation had two-factor at sign-in and
 * no re-challenge. It now has one.
 *
 * **A window, not a second password field on every form.** Fifteen minutes by
 * default. An operator confirming a password once per quarter-hour is following a
 * rule; one confirming it per action is working around a rule — and the way they
 * work around it is a password in a text file, which is worse than not having the
 * check at all.
 *
 * **It is the last line of defence and never the only one.** The organization
 * boundary, the permission and the policy all still apply and all run first. This
 * answers a different question from any of them: not "may this person do it" but
 * "is this person still at the keyboard". A stolen session cookie passes every
 * other check in the product and fails this one.
 *
 * Applied by route rather than by permission, deliberately. `PermissionDefinition`
 * already marks the high-risk capabilities and it would be tempting to drive this
 * from that flag — but "high risk" there means "audit a super-admin bypass of it",
 * which is a different and much broader set. Terminating a service and deleting a
 * role want this; viewing an audit trail is high-risk in that sense and plainly
 * does not.
 *
 * On an API request it answers **403 with a machine-readable code** rather than
 * redirecting: a token holder has no password to re-enter, and a redirect to a
 * confirmation form would be a redirect a script follows and cannot satisfy.
 */
final readonly class RequireRecentAuthentication
{
    public const string SESSION_KEY = 'auth.confirmed_at';

    public function handle(Request $request, Closure $next): Response
    {
        if ($this->recentlyConfirmed($request)) {
            return $next($request);
        }

        if ($request->expectsJson() || $request->is('api/*')) {
            // A token holder has no password to re-enter. Saying so is more
            // useful than a redirect a script will follow and not understand.
            return new JsonResponse([
                'error' => [
                    'code' => 'recent_authentication_required',
                    'message' => (string) __('identity.auth.recent_required'),
                ],
            ], 403);
        }

        // Where they were going, so the confirmation form can send them back
        // rather than dropping them on a dashboard.
        $request->session()->put('auth.intended', $request->fullUrl());

        return to_route($this->confirmRoute($request));
    }

    /**
     * Whether the password was confirmed inside the window.
     *
     * Read from the session rather than from the user row, because it is a fact
     * about *this* session: somebody who confirmed on their laptop has not
     * confirmed on the shared machine in the office.
     */
    private function recentlyConfirmed(Request $request): bool
    {
        if (! $request->hasSession()) {
            return false;
        }

        $at = $request->session()->get(self::SESSION_KEY);

        if (! is_int($at)) {
            return false;
        }

        return $at > time() - $this->window();
    }

    private function window(): int
    {
        $minutes = config('platform.security.reauth_minutes', 15);

        // A window of zero would mean confirming on every action, which is the
        // rule people work around. A negative one would mean never.
        return max(60, is_numeric($minutes) ? (int) $minutes * 60 : 900);
    }

    /**
     * The confirmation screen for whichever area this is.
     *
     * The two guards have separate sign-in screens and separate sessions, so
     * they need separate confirmation screens — sending a client to the admin
     * form would ask them for a password against the wrong guard.
     */
    private function confirmRoute(Request $request): string
    {
        return $request->is('admin', 'admin/*')
            ? 'admin.password.confirm'
            : 'client.password.confirm';
    }
}
