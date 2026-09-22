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
 */
final readonly class TrackAuthenticatedSession
{
    public function __construct(
        private SessionRegistry $sessions,
        private CurrentActor $actor,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        if ($this->actor->check() && $request->hasSession()) {
            $this->sessions->touch($request->session()->getId());
        }

        return $next($request);
    }
}
