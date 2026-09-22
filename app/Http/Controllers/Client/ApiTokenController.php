<?php

declare(strict_types=1);

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Http\Requests\Client\ApiTokenRequest;
use App\Support\Audit\Facades\Audit;
use App\Support\Errors\ForbiddenException;
use App\Support\Identity\CurrentActor;
use App\Support\Identity\CurrentCustomer;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * API tokens, issued by the customer to themselves.
 *
 * A token is a password that never gets typed, never expires unless someone
 * says so, and is worth exactly as much as the account it belongs to. Three
 * consequences, all of them visible in this class:
 *
 * - It is shown **once**, on the redirect that created it, and is never
 *   readable again. The table stores a hash; there is nothing to show
 *   later even if a screen asked.
 * - Issuing and revoking are both audited, with the token's name, because
 *   "who gave this thing access" is the first question after an incident.
 * - A staff member impersonating a customer cannot issue one. Whatever the
 *   reason for the impersonation, it is not to walk out with a credential.
 *
 * Abilities are reserved for Phase 10, which defines the scopes. Until then
 * a token carries `*` and the public API it would reach does not exist.
 */
final class ApiTokenController extends Controller
{
    public function __construct(
        private readonly CurrentCustomer $customer,
        private readonly CurrentActor $actor,
    ) {}

    public function index(): Response
    {
        $this->authorizeTokens();

        $contact = $this->customer->contact();

        return Inertia::render('Client/Developer/Tokens', [
            'tokens' => $contact->tokens()
                ->latest()
                ->get()
                ->map(fn (PersonalAccessToken $token): array => [
                    'id' => (string) $token->getKey(),
                    'name' => $token->name,
                    'lastUsedAt' => $token->last_used_at?->toIso8601String(),
                    'expiresAt' => $token->expires_at?->toIso8601String(),
                    'createdAt' => $token->created_at?->toIso8601String(),
                ])
                ->values()
                ->all(),
            // Handed back exactly once, on the redirect after creation.
            'issued' => session('issuedToken'),
        ]);
    }

    public function store(ApiTokenRequest $request): RedirectResponse
    {
        $this->authorizeTokens();

        $contact = $this->customer->contact();
        $days = $request->integer('expires_in_days');

        $token = $contact->createToken(
            $request->string('name')->toString(),
            ['*'],
            $days > 0 ? CarbonImmutable::now()->addDays($days) : null,
        );

        Audit::action('portal.api_token.created')
            ->by($contact)
            ->on($contact)
            ->withMetadata([
                'name' => $token->accessToken->name,
                'expires_at' => $token->accessToken->expires_at?->toIso8601String(),
            ])
            ->write();

        return back()
            ->with('status', __('identity.tokens.created'))
            // The only time this value exists in a readable form. Flashed,
            // so a refresh does not show it again.
            ->with('issuedToken', $token->plainTextToken);
    }

    public function destroy(string $token): RedirectResponse
    {
        $this->authorizeTokens();

        $contact = $this->customer->contact();

        /** @var PersonalAccessToken|null $stored */
        $stored = $contact->tokens()->whereKey($token)->first();

        if ($stored === null) {
            // Somebody else's token, or one already revoked. Same answer
            // either way: a token id is not proof of anything.
            abort(404);
        }

        $name = $stored->name;
        $stored->delete();

        Audit::action('portal.api_token.revoked')
            ->by($contact)
            ->on($contact)
            ->withMetadata(['name' => $name])
            ->write();

        return back()->with('status', __('identity.tokens.revoked'));
    }

    private function authorizeTokens(): void
    {
        if (! $this->actor->can('portal.tokens.manage')) {
            throw new ForbiddenException(__('identity.tokens.not_permitted'));
        }
    }
}
