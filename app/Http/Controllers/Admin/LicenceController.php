<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Application\Licensing\InstallationIdentity;
use App\Application\Licensing\LicencePublicKey;
use App\Application\Licensing\LicenceState;
use App\Application\Licensing\Licensing;
use App\Domain\Licensing\Exceptions\LicenceRefused;
use App\Domain\Licensing\Exceptions\LicenceUnreachable;
use App\Http\Controllers\Controller;
use App\Infrastructure\Audit\Models\AuditLog;
use App\Support\Errors\ForbiddenException;
use App\Support\Identity\CurrentActor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * This installation's relationship with the vendor.
 *
 * **Owner only**, like Apps and Integrations and for the same reason: it is not
 * a question of what somebody may do but of who they are. An Administrator
 * holds every staff permission by design, and a reseller's Administrator is an
 * Administrator — a reseller who could deactivate the installation's licence
 * would be a reseller able to turn the vendor mark back on for the provider.
 *
 * The screen shows what the licence *is* and never what it is keyed by. The
 * licence key is the one secret in this context: it is write-only here, it is
 * redacted from the log, and a test asserts neither it nor the public key ever
 * reaches the payload.
 *
 * **Everything an operator can do here is slow and remote**, so every action
 * reports what happened rather than redirecting silently — and a refusal says
 * which refusal it was, because "licence invalid" is not something anybody can
 * act on.
 */
final class LicenceController extends Controller
{
    public function __construct(private readonly CurrentActor $actor) {}

    public function index(InstallationIdentity $identity, LicencePublicKey $publicKey): Response
    {
        $this->authorizeOwner();

        $state = LicenceState::load();

        return Inertia::render('Admin/Licence/Index', [
            'installation' => [
                // The installation's own identity, which the vendor needs when
                // an operator telephones about an activation. Not a secret: it
                // proves nothing on its own.
                'id' => $identity->id(),
                'claims' => $identity->claims(),
            ],
            'configured' => [
                // Whether, not what. "An API URL is set" is what an operator
                // needs to know; the URL itself is configuration.
                'api' => is_string(config('platform.licensing.api_url'))
                    && trim(config('platform.licensing.api_url')) !== '',
                'key' => is_string(config('platform.licensing.key'))
                    && trim(config('platform.licensing.key')) !== '',
                'publicKey' => $publicKey->exists(),
                'graceDays' => (int) config('platform.licensing.grace_days', 30),
            ],
            'licence' => [
                'configured' => $state->configured,
                'licenceId' => $state->licenceId,
                'edition' => $state->edition,
                'status' => $state->status->value,
                'statusLabel' => (string) __($state->status->labelKey()),
                'excluded' => $state->excluded,
                'limits' => $state->limits,
                'issuedAt' => $state->issuedAt?->toIso8601String(),
                'expiresAt' => $state->expiresAt?->toIso8601String(),
                'heartbeatBy' => $state->heartbeatBy?->toIso8601String(),
                'graceUntil' => $state->graceUntil?->toIso8601String(),
                'lastContactAt' => $state->lastContactAt?->toIso8601String(),
                'lastFailure' => $state->lastFailure,
                'isLive' => $state->isLive(),
                'isInGrace' => $state->isInGrace(),
            ],
            // What has happened to this licence, which is the history a vendor
            // will ask about. Read from the audit trail rather than kept twice.
            'history' => $this->history(),
        ]);
    }

    public function activate(Request $request, Licensing $licensing): RedirectResponse
    {
        $this->authorizeOwner();

        $data = $request->validate([
            'licence_key' => ['required', 'string', 'max:191'],
        ]);

        try {
            $state = $licensing->activate(trim($data['licence_key']), $this->actor->model());
        } catch (LicenceRefused|LicenceUnreachable $failure) {
            // On the field rather than on an error page: the operator typed a
            // key and the key is what this is about. The message is already
            // sanitised — neither exception carries a response body.
            throw ValidationException::withMessages(['licence_key' => $failure->getMessage()]);
        }

        return back()->with('status', __('licensing.activated', [
            'edition' => $state->edition ?? '—',
        ]));
    }

    /**
     * Speak to the vendor now, rather than waiting for the hourly task.
     *
     * Exists because the first thing anybody does after a licence change is
     * press this, and telling them to wait an hour is telling them the screen
     * is broken.
     */
    public function heartbeat(Licensing $licensing): RedirectResponse
    {
        $this->authorizeOwner();

        try {
            $state = $licensing->heartbeat($this->actor->model());
        } catch (LicenceRefused $refused) {
            return back()->with('error', $refused->getMessage());
        }

        if ($state->lastFailure !== null) {
            // Inside grace: nothing changed, and saying so plainly is more
            // useful than a success message that was not true.
            return back()->with('error', $state->lastFailure);
        }

        return back()->with('status', __('licensing.heartbeat_ok'));
    }

    public function deactivate(Licensing $licensing): RedirectResponse
    {
        $this->authorizeOwner();

        $licensing->deactivate($this->actor->model());

        return back()->with('status', __('licensing.deactivated'));
    }

    /**
     * Who you have to be, rather than what you may do.
     *
     * Not a permission, and it cannot be one: an Administrator holds every
     * staff permission there is by design, and a reseller's Administrator is
     * an Administrator.
     */
    private function authorizeOwner(): void
    {
        if (! $this->actor->isSuperAdmin()) {
            throw new ForbiddenException(__('licensing.errors.not_permitted'));
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function history(): array
    {
        return array_values(AuditLog::query()
            ->where('action', 'like', 'licensing.%')
            ->latest('occurred_at')
            ->limit(20)
            ->get()
            ->map(static fn (AuditLog $record): array => [
                'id' => $record->id,
                'action' => $record->action,
                'actor' => $record->actor_label,
                'reason' => $record->reason,
                'at' => $record->occurred_at->toIso8601String(),
            ])
            ->all());
    }
}
