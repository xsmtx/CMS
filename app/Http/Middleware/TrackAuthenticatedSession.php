<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Application\Identity\SessionRegistry;
use App\Support\Identity\CurrentActor;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Keeps the session mirror current.
 *
 * Touch is throttled inside the registry, so this costs one indexed read per
 * request and a write at most once a minute per session.
 *
 * It also **registers** a session the registry has not seen, because a
 * remember-me cookie produces an authenticated request with a session id
 * nothing ever recorded — and a device list that leaves out the device
 * reading it is worse than none.
 */
final readonly class TrackAuthenticatedSession
{
    public function __construct(
        private SessionRegistry $sessions,
        private CurrentActor $actor,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $guard = $this->actor->guard();
        $subject = $this->actor->model();

        if ($guard !== null && $subject !== null && $request->hasSession()) {
            $this->sessions->track($guard, $subject, $request->session()->getId());
        }

        return $next($request);
    }
}
