<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Api\StaffApiScope;
use App\Domain\Identity\AccountStatus;
use App\Infrastructure\Identity\Models\StaffUser;
use App\Support\Errors\UnauthenticatedException;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

/**
 * Turns a bearer token into an ordinary staff actor (ADR 0049).
 *
 * The same decision `AuthenticateApiToken` made for the client guard, and the
 * same reason: the API does not get its own identity, its own boundary or its
 * own permission check. It authenticates, puts the token's staff user on the
 * `staff` guard, and `CurrentActor`, the organization boundary and every
 * policy behave exactly as they do for somebody signed into the admin area. A
 * surface that resolved identity differently would eventually authorize
 * differently, and that divergence is how an API becomes the way in.
 *
 * Three refusals this one adds to that one, all of them still the same
 * `unauthenticated` to the caller:
 *
 * **An expired token.** A staff token always has an expiry — there is no
 * unlimited option, unlike a client token, because a staff token exists for a
 * phone rather than for a machine in a rack.
 *
 * **A token carrying no scope.** There is no `*` and never was: a staff token
 * that narrowed nothing would be every staff permission in a bearer string,
 * which is the problem ADR 0049 exists to solve.
 *
 * **A staff user who is no longer active.** Revoking the account revokes the
 * tokens, which is the property that makes issuing one safe at all.
 */
final readonly class AuthenticateStaffApiToken
{
    public function __construct(private ResolveOrganizationContext $boundary) {}

    public function handle(Request $request, Closure $next): Response
    {
        $presented = $request->bearerToken();

        if ($presented === null || $presented === '') {
            throw $this->refuse();
        }

        $token = PersonalAccessToken::findToken($presented);

        if (! $token instanceof PersonalAccessToken) {
            throw $this->refuse();
        }

        /*
         * An expiry is required rather than merely honoured. A staff token
         * with a null `expires_at` cannot be issued by anything in this
         * codebase, so one that exists came from somewhere else — and a
         * bearer string that never dies is exactly what must not work here.
         */
        if ($token->expires_at === null || CarbonImmutable::parse($token->expires_at)->isPast()) {
            throw $this->refuse();
        }

        $scopes = $this->scopesOf($token);

        if ($scopes === []) {
            throw $this->refuse();
        }

        $staff = $token->tokenable;

        if (! $staff instanceof StaffUser || $staff->status !== AccountStatus::Active) {
            throw $this->refuse();
        }

        Auth::guard('staff')->setUser($staff);

        $request->attributes->set('api_token', $token);
        $request->attributes->set('api_staff_scopes', $scopes);

        // Recorded before the work, not after: a token used by a request that
        // then throws has still been used, and "when was this last used" is
        // the question asked before revoking one.
        $token->forceFill(['last_used_at' => CarbonImmutable::now()])->save();

        return $this->boundary->handle($request, $next);
    }

    /**
     * The scopes on the token, as the enum knows them.
     *
     * A value this version does not recognise is dropped rather than kept as
     * a string: a token minted against a later release must not carry a scope
     * this one cannot reason about.
     *
     * @return list<StaffApiScope>
     */
    private function scopesOf(PersonalAccessToken $token): array
    {
        /** @var list<string> $abilities */
        $abilities = is_array($token->abilities) ? array_values($token->abilities) : [];

        return array_values(array_filter(array_map(
            StaffApiScope::tryFrom(...),
            $abilities,
        )));
    }

    private function refuse(): UnauthenticatedException
    {
        return new UnauthenticatedException((string) __('api.errors.unauthenticated'));
    }
}
