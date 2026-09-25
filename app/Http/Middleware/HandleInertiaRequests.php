<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Application\Identity\Impersonator;
use App\Application\Operations\OperationFeed;
use App\Application\Tax\TaxIdentity;
use App\Domain\Modules\NavigationItem;
use App\Infrastructure\Modules\ActiveModules;
use App\Support\Branding\CurrentBrand;
use App\Support\Correlation\CorrelationContext;
use App\Support\Identity\CurrentActor;
use App\Support\Locales;
use Illuminate\Http\Request;
use Inertia\Inertia;
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
            // Rows the enabled modules added, and where this installation
            // sends its operators for help. Both are closures: neither is
            // needed to render a redirect, and the modules one would load
            // every enabled package to answer.
            'moduleNavigation' => $this->moduleNavigation(...),
            'help' => fn (): array => array_filter((array) config('platform.help', [])),
            'impersonation' => fn (): ?array => $this->impersonation($request),
            // A brand is a row, not a config value (ADR 0036). Resolved
            // per request from whoever is looking: reseller staff see their
            // own name, and so do their customers.
            'brand' => fn (): array => app(CurrentBrand::class)->current()->toArray(),
            /*
             * What this seller calls a tax id, and whether a business has to
             * give one. Shared rather than passed per screen because five forms
             * ask for it — checkout, the client's billing details, their
             * profile, and both admin customer forms — and a label that is
             * right on four of them is a label somebody will trust on the
             * fifth.
             *
             * A closure: neither value is needed to render a redirect, and the
             * lookup is a query against the seller's row.
             */
            'taxIdentity' => fn (): array => app(TaxIdentity::class)->current(),
            'locale' => app()->getLocale(),
            // What else this installation speaks. Shared rather than passed
            // per screen because the switch is in the chrome, on every page.
            'locales' => Locales::options(),
            'flash' => [
                'success' => fn (): ?string => $request->session()->get('success'),
                'error' => fn (): ?string => $request->session()->get('error'),
                'status' => fn (): ?string => $request->session()->get('status'),
                // Flashed once, by the endpoint that records the operator
                // asking for them. Never stored and never sent again.
                'credentials' => fn (): ?array => $request->session()->get('credentials'),
            ],
            'correlationId' => app(CorrelationContext::class)->id(),

            // An operation is visible before it finishes (ADR 0032), and the
            // drawer is how somebody who was not looking finds out. Two
            // counts on every admin render, and the list only when the
            // drawer asks for it.
            'operations' => $this->operationCounts(...),
            'operationQueue' => Inertia::optional(fn (): array => $this->operationQueue()),
        ];
    }

    /**
     * The two numbers the topbar shows, or `null` when nobody may see them.
     *
     * Staff only, and only with the permission the Operations screen needs:
     * a customer's portal page shares these props too, and a count of the
     * platform's failed provisioning runs is not theirs.
     *
     * @return array{active: int, attention: int}|null
     */
    private function operationCounts(): ?array
    {
        $actor = app(CurrentActor::class);

        if (! $actor->isStaff() || ! $actor->can('operations.view')) {
            return null;
        }

        return app(OperationFeed::class)->summary();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function operationQueue(): array
    {
        $actor = app(CurrentActor::class);

        if (! $actor->isStaff() || ! $actor->can('operations.view')) {
            return [];
        }

        return app(OperationFeed::class)->recent();
    }

    /**
     * The banner shown while a staff member is acting as a customer.
     *
     * Not dismissible and not optional: the whole safeguard is that the
     * person can always see it is not really their session.
     *
     * @return array<string, mixed>|null
     */
    /**
     * What the enabled modules want in the menu.
     *
     * Only rows whose permission the viewer holds, and only from modules
     * that are running. A module cannot choose where its row goes: it lands
     * under Addons, because a row that looked like Billing would be
     * indistinguishable from the platform's own.
     *
     * @return list<array<string, mixed>>
     */
    private function moduleNavigation(): array
    {
        $actor = app(CurrentActor::class);

        if (! $actor->isStaff()) {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn (NavigationItem $item): ?array => $item->permission !== null
                && ! $actor->can($item->permission)
                    ? null
                    : ['label' => $item->label, 'href' => $item->path],
            app(ActiveModules::class)->navigation(),
        )));
    }

    /**
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
