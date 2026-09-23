<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Api\ApiScope;
use App\Support\Errors\ForbiddenException;
use App\Support\Identity\CurrentActor;
use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

/**
 * The fourth authorization question.
 *
 * Asked after the three this platform asks everywhere — organization
 * boundary, resource ownership, permission — and it can only ever narrow.
 * Two checks, in this order, and the order is the point:
 *
 * 1. **Does the token carry the scope?** That is what the person consented
 *    to share with this integration.
 * 2. **Does the holder have the permissions behind it?** That is what the
 *    platform allows the person to do at all.
 *
 * A token with `services:write` held by a contact without
 * `portal.services.view` reaches nothing. Without the second check a scope
 * would be a grant rather than a filter, and an API token would be a way to
 * do things its owner cannot — which is the definition of a privilege
 * escalation, arrived at by accident.
 */
final readonly class RequireApiScope
{
    public function __construct(private CurrentActor $actor) {}

    public function handle(Request $request, Closure $next, string $scope): Response
    {
        $required = ApiScope::tryFrom($scope);

        if (! $required instanceof ApiScope) {
            // A route asking for a scope that does not exist is a bug in
            // this codebase, and it must fail closed.
            throw new ForbiddenException((string) __('api.errors.forbidden'));
        }

        /** @var list<ApiScope> $granted */
        $granted = $request->attributes->get('api_scopes', []);

        if (! in_array($required, $granted, strict: true)) {
            throw new ForbiddenException((string) __('api.errors.scope_missing', [
                'scope' => $required->value,
            ]));
        }

        foreach ($required->requiredPermissions() as $permission) {
            if (! $this->actor->can($permission)) {
                // Deliberately the same message as a missing scope: which
                // of the two failed is information about the account.
                throw new ForbiddenException((string) __('api.errors.scope_missing', [
                    'scope' => $required->value,
                ]));
            }
        }

        return $next($request);
    }

    /**
     * The token behind the current request, for anything that needs to
     * record which one asked.
     */
    public static function tokenFor(Request $request): ?PersonalAccessToken
    {
        $token = $request->attributes->get('api_token');

        return $token instanceof PersonalAccessToken ? $token : null;
    }
}
