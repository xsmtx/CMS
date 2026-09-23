<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Api\ApiScope;
use App\Infrastructure\Identity\Models\Contact;
use App\Support\Errors\UnauthenticatedException;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

/**
 * Turns a bearer token into an ordinary client actor.
 *
 * That last word is the decision. The API does not get its own identity, its
 * own boundary or its own permission check: it authenticates, puts the
 * token's contact on the `client` guard, and everything downstream —
 * `CurrentActor`, `CurrentCustomer`, the organization boundary, every
 * policy — behaves exactly as it does for somebody signed into the portal.
 * A surface that resolved identity differently would eventually authorize
 * differently, and that divergence is how an API becomes the way in.
 *
 * The boundary is then established by the same middleware the browser
 * request uses, called here rather than re-implemented, because there is
 * one correct way to decide which rows exist and it is already written.
 *
 * Four refusals, all of them `unauthenticated` and none of them explaining
 * which: a caller learning that a token exists but has expired knows more
 * than a caller learning nothing.
 */
final readonly class AuthenticateApiToken
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

        if ($token->expires_at !== null && CarbonImmutable::parse($token->expires_at)->isPast()) {
            throw $this->refuse();
        }

        $contact = $token->tokenable;

        // A token belongs to a person. Revoking their portal access revokes
        // the token with it, which is the property that makes a token safe
        // to issue at all.
        if (! $contact instanceof Contact || ! $contact->portal_access) {
            throw $this->refuse();
        }

        Auth::guard('client')->setUser($contact);

        $request->attributes->set('api_token', $token);
        $request->attributes->set('api_scopes', $this->scopesOf($token));

        // Recorded before the work, not after: a token used by a job that
        // then throws has still been used, and "when was this last used"
        // is the question asked before revoking one.
        $token->forceFill(['last_used_at' => CarbonImmutable::now()])->save();

        return $this->boundary->handle($request, $next);
    }

    /**
     * The scopes this token actually carries.
     *
     * A token issued before Phase 10 holds `*`, which used to mean
     * "everything" and now means nothing: it was issued when no API
     * existed, so it consented to nothing. Treating it as full access would
     * hand every pre-existing token the whole surface on upgrade day.
     *
     * @return list<ApiScope>
     */
    private function scopesOf(PersonalAccessToken $token): array
    {
        /** @var list<string> $abilities */
        $abilities = $token->abilities ?? [];

        return array_values(array_filter(array_map(
            ApiScope::tryFrom(...),
            $abilities,
        )));
    }

    private function refuse(): UnauthenticatedException
    {
        return new UnauthenticatedException((string) __('api.errors.unauthenticated'));
    }
}
