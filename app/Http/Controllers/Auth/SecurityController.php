<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Application\Identity\SessionRegistry;
use App\Application\Identity\TwoFactorAuthenticator;
use App\Http\Controllers\Auth\Concerns\ResolvesGuard;
use App\Http\Controllers\Controller;
use App\Http\Requests\Identity\PasswordUpdateRequest;
use App\Http\Requests\Identity\TwoFactorConfirmRequest;
use App\Infrastructure\Identity\Contracts\AuthenticatableAccount;
use App\Infrastructure\Identity\Models\AuthenticatedSession;
use App\Infrastructure\Identity\Models\LoginHistory;
use App\Support\Audit\Facades\Audit;
use App\Support\Branding\CurrentBrand;
use App\Support\Identity\CurrentActor;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Account security, shared by both guards: password, two-factor, sessions.
 *
 * Every route here is blocked while impersonating. A staff member acting as
 * a customer must never be able to change that customer's password or turn
 * off their second factor.
 */
final class SecurityController extends Controller
{
    use ResolvesGuard;

    public function __construct(
        private readonly TwoFactorAuthenticator $twoFactor,
        private readonly SessionRegistry $sessions,
        private readonly CurrentActor $actor,
        private readonly CurrentBrand $brands,
    ) {}

    public function show(Request $request): Response
    {
        $subject = $this->subject();

        return Inertia::render('Security/Index', [
            'guard' => $this->guard($request)->value,
            'twoFactor' => [
                'enabled' => $subject->hasTwoFactorEnabled(),
                'pending' => $subject->hasPendingTwoFactorSetup(),
                'recoveryCodeCount' => count($subject->twoFactorRecoveryCodeHashes()),
            ],
            'sessions' => $this->sessions->forSubject($subject)->map(
                fn (AuthenticatedSession $session): array => [
                    'id' => $session->id,
                    'ipAddress' => $session->ip_address,
                    'userAgent' => $session->user_agent,
                    'lastActiveAt' => $session->last_active_at->toIso8601String(),
                    'current' => $session->isCurrent($request->session()->getId()),
                ],
            )->values(),
            'loginHistory' => LoginHistory::query()
                ->where('subject_type', $subject->getMorphClass())
                ->where('subject_id', (string) $subject->getKey())
                ->latest('occurred_at')
                ->limit(10)
                ->get()
                ->map(fn (LoginHistory $entry): array => [
                    'id' => $entry->id,
                    'successful' => $entry->successful,
                    'reason' => $entry->failure_reason?->value,
                    'ipAddress' => $entry->ip_address,
                    'occurredAt' => $entry->occurred_at->toIso8601String(),
                ])
                ->values(),
        ]);
    }

    public function updatePassword(PasswordUpdateRequest $request): RedirectResponse
    {
        $subject = $this->subject();

        $subject->forceFill([
            'password' => $request->string('password')->toString(),
            'password_changed_at' => CarbonImmutable::now(),
        ])->save();

        // Changing a password is how someone responds to a suspected
        // compromise, so every other session goes with it.
        $revoked = $this->sessions->revokeOthers($subject, $request->session()->getId());

        Audit::action('identity.password.changed')
            ->by($subject)
            ->on($subject)
            ->withMetadata(['sessions_revoked' => $revoked])
            ->write();

        return back()->with('status', __('identity.auth.password_updated'));
    }

    /**
     * Step one of enrolment: generate a secret and show the QR code. The
     * secret is inactive until confirmed.
     */
    public function beginTwoFactor(Request $request): RedirectResponse
    {
        $subject = $this->subject();

        $this->twoFactor->beginEnrolment($subject);

        return back();
    }

    public function twoFactorSetup(Request $request): Response
    {
        $subject = $this->subject();

        abort_unless($subject->hasPendingTwoFactorSetup() || $subject->hasTwoFactorEnabled(), 404);

        // The brand, not the installation: somebody enrolling on a
        // reseller's panel should see the reseller's name in their
        // authenticator, beside the six other codes they already have.
        $uri = $this->twoFactor->provisioningUri($subject, $this->brands->current()->name);

        return Inertia::render('Security/TwoFactorSetup', [
            'guard' => $this->guard($request)->value,
            'qrCode' => $this->twoFactor->qrCodeSvg($uri),
            // Shown so a device that cannot scan can still be enrolled.
            'secret' => (string) $subject->getAttribute('two_factor_secret'),
            'confirmed' => $subject->hasTwoFactorEnabled(),
        ]);
    }

    public function confirmTwoFactor(TwoFactorConfirmRequest $request): RedirectResponse
    {
        $subject = $this->subject();

        $codes = $this->twoFactor->confirmEnrolment($subject, $request->string('code')->toString());

        if ($codes === null) {
            throw ValidationException::withMessages([
                'code' => __('identity.two_factor.confirm_failed'),
            ]);
        }

        // Flashed, not stored: this is the only time the plain codes exist.
        return back()->with('recoveryCodes', $codes)->with('status', __('identity.two_factor.enabled'));
    }

    public function regenerateRecoveryCodes(Request $request): RedirectResponse
    {
        $subject = $this->subject();

        abort_unless($subject->hasTwoFactorEnabled(), 404);

        $codes = $this->twoFactor->regenerateRecoveryCodes($subject);

        return back()
            ->with('recoveryCodes', $codes)
            ->with('status', __('identity.two_factor.recovery_codes_regenerated'));
    }

    public function disableTwoFactor(Request $request): RedirectResponse
    {
        $this->twoFactor->disable($this->subject());

        return back()->with('status', __('identity.two_factor.disabled'));
    }

    public function revokeSession(Request $request, string $session): RedirectResponse
    {
        $subject = $this->subject();

        $record = AuthenticatedSession::query()
            ->where('subject_type', $subject->getMorphClass())
            ->where('subject_id', (string) $subject->getKey())
            ->findOrFail($session);

        $this->sessions->revoke($record);

        Audit::action('identity.session.revoked')
            ->by($subject)
            ->on($subject)
            ->withMetadata(['revoked_session' => $record->id])
            ->write();

        return back();
    }

    public function revokeOtherSessions(Request $request): RedirectResponse
    {
        $subject = $this->subject();

        $revoked = $this->sessions->revokeOthers($subject, $request->session()->getId());

        Audit::action('identity.session.revoked_others')
            ->by($subject)
            ->on($subject)
            ->withMetadata(['count' => $revoked])
            ->write();

        return back()->with('status', __('identity.auth.signed_out_others', ['count' => $revoked]));
    }

    private function subject(): Model&AuthenticatableAccount
    {
        $subject = $this->actor->account();

        abort_if($subject === null, 401);

        return $subject;
    }
}
