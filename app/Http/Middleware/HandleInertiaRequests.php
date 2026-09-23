<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Application\Identity\Impersonator;
use App\Support\Branding\CurrentBrand;
use App\Support\Correlation\CorrelationContext;
use App\Support\Identity\CurrentActor;
use Illuminate\Http\Request;
use Inertia\Middleware;

/**
 * Props shared with every Inertia page.
 *
 * Everything here is serialised into the page payload, so it must never
 * contain a secret, a token, or an identifier the viewer is not already
 * allowed to see.
 */
final class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $actor = app(CurrentActor::class);
        $subject = $actor->model();

        return [
            ...parent::share($request),
            'auth' => [
                'user' => $subject === null ? null : [
                    'id' => (string) $subject->getKey(),
                    'name' => method_exists($subject, 'displayName')
                        ? $subject->displayName()
                        : (string) $subject->getAttribute('name'),
                    'email' => (string) $subject->getAttribute('email'),
                ],
                'guard' => $actor->guard()?->value,
                'permissions' => $subject !== null && method_exists($subject, 'effectivePermissions')
                    ? $subject->effectivePermissions()
                    : [],
                // Not a permission, and it cannot be one: an Administrator
                // holds every staff permission by design, so a new one
                // would reach both roles. Apps and Integrations is about
                // who somebody is rather than what they may do.
                'isSuperAdmin' => $actor->isSuperAdmin(),
            ],
            'impersonation' => fn (): ?array => $this->impersonation($request),
            // A brand is a row, not a config value (ADR 0036). Resolved
            // per request from whoever is looking: reseller staff see their
            // own name, and so do their customers.
            'brand' => fn (): array => app(CurrentBrand::class)->current()->toArray(),
            'locale' => app()->getLocale(),
            'flash' => [
                'success' => fn (): ?string => $request->session()->get('success'),
                'error' => fn (): ?string => $request->session()->get('error'),
                'status' => fn (): ?string => $request->session()->get('status'),
                // Flashed once, by the endpoint that records the operator
                // asking for them. Never stored and never sent again.
                'credentials' => fn (): ?array => $request->session()->get('credentials'),
            ],
            'correlationId' => app(CorrelationContext::class)->id(),
        ];
    }

    /**
     * The banner shown while a staff member is acting as a customer.
     *
     * Not dismissible and not optional: the whole safeguard is that the
     * person can always see it is not really their session.
     *
     * @return array<string, mixed>|null
     */
    private function impersonation(Request $request): ?array
    {
        $payload = $request->session()->get(Impersonator::SESSION_KEY);

        if (! is_array($payload)) {
            return null;
        }

        return [
            'active' => true,
            'subjectName' => $payload['subject_name'] ?? null,
            'impersonatorName' => $payload['impersonator_name'] ?? null,
        ];
    }
}
