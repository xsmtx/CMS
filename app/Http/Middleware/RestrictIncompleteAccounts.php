<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\Errors\ForbiddenException;
use App\Support\Identity\CurrentCustomer;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Holds an account whose details are missing or wrong.
 *
 * **Support stays open.** An account blocked pending information has to be
 * able to tell somebody what is wrong with their information, and a block
 * that also closed the ticket form would leave them with no way to become
 * un-blocked — a support queue full of people phoning instead.
 *
 * So does the profile, because "fix your details" is not an instruction
 * somebody can follow on a page they cannot reach, and so does signing out.
 *
 * Enforced here rather than hidden in the navigation. Hiding a link is
 * presentation; this is the rule, and it re-checks on every request the way
 * every other rule in this platform does.
 */
final readonly class RestrictIncompleteAccounts
{
    /**
     * Path prefixes that stay reachable, relative to `/client`.
     */
    private const array ALLOWED = [
        'support',
        'profile',
        'notifications',
        'contacts',
    ];

    public function __construct(private CurrentCustomer $customer) {}

    public function handle(Request $request, Closure $next): Response
    {
        try {
            $customer = $this->customer->model();
        } catch (NotFoundHttpException) {
            // No customer behind this session. Not this middleware's
            // problem to answer.
            return $next($request);
        }

        if (! $customer->status->isRestrictedToSupport()) {
            return $next($request);
        }

        if ($this->isAllowed($request)) {
            return $next($request);
        }

        throw new ForbiddenException(__('crm.errors.information_required'));
    }

    private function isAllowed(Request $request): bool
    {
        $path = trim(substr($request->path(), strlen('client')), '/');

        if ($path === '') {
            // The dashboard would show services and invoices they may not
            // reach. The profile is where they are sent instead.
            return false;
        }

        return array_any(self::ALLOWED, fn (string $prefix): bool => $path === $prefix || str_starts_with($path, $prefix.'/'));
    }
}
