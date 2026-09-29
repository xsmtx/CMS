<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Api\StaffApiScope;
use App\Support\Errors\ForbiddenException;
use App\Support\Identity\CurrentActor;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The fourth question, on the staff surface (ADR 0033, ADR 0049).
 *
 * Two checks, in this order, and the order is the point:
 *
 * 1. **Does the token carry the scope?** That is what the operator chose to
 *    put on this device.
 * 2. **Does the holder have the permissions behind it?** That is what their
 *    role allows them to do at all.
 *
 * The second is what keeps a scope a filter rather than a grant. Without it
 * a staff token would be a way to do things its owner cannot, which is a
 * privilege escalation arrived at by accident — and on this guard the ceiling
 * is every permission on the installation rather than one customer's account.
 */
final readonly class RequireStaffApiScope
{
    public function __construct(private CurrentActor $actor) {}

    public function handle(Request $request, Closure $next, string $scope): Response
    {
        $required = StaffApiScope::tryFrom($scope);

        if (! $required instanceof StaffApiScope) {
            // A route asking for a scope that does not exist is a bug in
            // this codebase, and it must fail closed.
            throw new ForbiddenException((string) __('api.errors.forbidden'));
        }

        /** @var list<StaffApiScope> $granted */
        $granted = $request->attributes->get('api_staff_scopes', []);

        if (! in_array($required, $granted, strict: true)) {
            throw $this->missing($required);
        }

        foreach ($required->requiredPermissions() as $permission) {
            if (! $this->actor->can($permission)) {
                // Deliberately the same refusal as a missing scope: which of
                // the two failed is information about the account.
                throw $this->missing($required);
            }
        }

        return $next($request);
    }

    private function missing(StaffApiScope $scope): ForbiddenException
    {
        return new ForbiddenException((string) __('api.errors.scope_missing', [
            'scope' => $scope->value,
        ]));
    }
}
