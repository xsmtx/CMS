<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\Correlation\CorrelationContext;
use Illuminate\Http\Request;
use Inertia\Middleware;

/**
 * Props shared with every Inertia page.
 *
 * Everything here is serialised into the page payload, so it must never
 * contain a secret, a token or an internal identifier that the viewer is not
 * already allowed to see.
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
        $user = $request->user();

        return [
            ...parent::share($request),
            'auth' => [
                'user' => $user === null ? null : [
                    'id' => $user->getAuthIdentifier(),
                    'name' => $user->getAttribute('name'),
                    'email' => $user->getAttribute('email'),
                ],
                'permissions' => $user !== null && method_exists($user, 'effectivePermissions')
                    ? $user->effectivePermissions()
                    : [],
            ],
            'brand' => [
                'name' => config('app.name'),
            ],
            'locale' => app()->getLocale(),
            'flash' => [
                'success' => fn (): ?string => $request->session()->get('success'),
                'error' => fn (): ?string => $request->session()->get('error'),
            ],
            'correlationId' => app(CorrelationContext::class)->id(),
        ];
    }
}
