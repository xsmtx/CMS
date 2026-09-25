<?php

declare(strict_types=1);

namespace App\Application\Identity;

use App\Domain\Identity\Guard;
use App\Infrastructure\Identity\Models\AuthenticatedSession;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Session\SessionManager;
use Illuminate\Support\Collection;

/**
 * Keeps a queryable row per live session.
 *
 * Sessions live in Redis because that is the right store for them. Redis
 * cannot answer "which devices is this person signed in on", so this mirror
 * does, and revocation goes back through the session driver's own handler.
 * Swapping an installation to the database or file driver changes nothing
 * here.
 */
final readonly class SessionRegistry
{
    /**
     * Activity is only written once a minute per session. Without this,
     * every request on every page becomes a write.
     */
    private const int ACTIVITY_INTERVAL_SECONDS = 60;

    public function __construct(
        private Request $request,
        private SessionManager $sessions,
    ) {}

    public function register(Guard $guard, Model $subject, string $sessionId): void
    {
        AuthenticatedSession::query()->withoutGlobalScope('organization')->updateOrCreate(
            ['session_id' => $sessionId],
            [
                'organization_id' => $subject->getAttribute('organization_id'),
                'subject_type' => $subject->getMorphClass(),
                'subject_id' => (string) $subject->getKey(),
                'guard' => $guard->value,
                'ip_address' => $this->request->ip(),
                'user_agent' => mb_substr((string) $this->request->userAgent(), 0, 512),
                'last_active_at' => CarbonImmutable::now(),
            ],
        );
    }

    /**
     * Keep the mirror honest for the session actually being used.
     *
     * `touch()` alone could not. A session Laravel rebuilt from a remember-me
     * cookie carries an id the registry has never seen — nothing on that path
     * calls `complete()` — so the person's own device was **missing from
     * their own list of devices**, while the row for the session it replaced
     * sat there looking live with a Sign out button beside it. Any other
     * regeneration of the id has the same effect.
     *
     * Registering the unknown one costs the same indexed read either way, and
     * a list of sessions that leaves out the one you are reading it on is
     * worse than no list.
     */
    public function track(Guard $guard, Model $subject, string $sessionId): void
    {
        $session = AuthenticatedSession::query()
            ->withoutGlobalScope('organization')
            ->where('session_id', $sessionId)
            ->first();

        if ($session === null) {
            $this->register($guard, $subject, $sessionId);

            return;
        }

        if ($session->last_active_at->diffInSeconds(CarbonImmutable::now()) < self::ACTIVITY_INTERVAL_SECONDS) {
            return;
        }

        $session->forceFill([
            'last_active_at' => CarbonImmutable::now(),
            'ip_address' => $this->request->ip(),
        ])->save();
    }

    public function forget(string $sessionId): void
    {
        AuthenticatedSession::query()
            ->withoutGlobalScope('organization')
            ->where('session_id', $sessionId)
            ->delete();
    }

    /**
     * @return Collection<int, AuthenticatedSession>
     */
    public function forSubject(Model $subject): Collection
    {
        return AuthenticatedSession::query()
            ->where('subject_type', $subject->getMorphClass())
            ->where('subject_id', (string) $subject->getKey())
            ->latest('last_active_at')
            ->get();
    }

    /**
     * Sign the subject out everywhere except the session they are using.
     *
     * The row is removed *and* the session destroyed through the driver, so
     * a stolen cookie stops working immediately rather than at expiry.
     */
    public function revokeOthers(Model $subject, string $currentSessionId): int
    {
        $sessions = $this->forSubject($subject)
            ->reject(fn (AuthenticatedSession $session): bool => $session->isCurrent($currentSessionId));

        foreach ($sessions as $session) {
            $this->destroy($session);
        }

        return $sessions->count();
    }

    public function revoke(AuthenticatedSession $session): void
    {
        $this->destroy($session);
    }

    /**
     * The handler comes from the session manager rather than the current
     * request, so revocation also works from a console command and from a
     * queued job, neither of which has a session of its own.
     */
    private function destroy(AuthenticatedSession $session): void
    {
        $this->sessions->driver()->getHandler()->destroy($session->session_id);

        $session->delete();
    }
}
