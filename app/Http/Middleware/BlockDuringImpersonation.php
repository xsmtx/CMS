<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Application\Identity\Impersonator;
use App\Support\Errors\ForbiddenException;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Refuses security-sensitive actions while a staff member is acting as a
 * customer.
 *
 * Changing a password, managing two-factor or revoking sessions while
 * impersonating would let a staff member lock the real owner out of their own
 * account, and the audit trail would show the customer doing it. Applied to
 * those routes rather than left to reviewer discipline.
 */
final readonly class BlockDuringImpersonation
{
    public function __construct(private Impersonator $impersonator) {}

    public function handle(Request $request, Closure $next): Response
    {
        if ($this->impersonator->isImpersonating()) {
            throw new ForbiddenException(__('identity.impersonation.blocked_action'));
        }

        return $next($request);
    }
}
