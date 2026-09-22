<?php

declare(strict_types=1);

use App\Http\Middleware\AssignCorrelationId;
use App\Http\Middleware\BlockDuringImpersonation;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\ResolveOrganizationContext;
use App\Http\Middleware\TrackAuthenticatedSession;
use App\Support\Correlation\CorrelationContext;
use App\Support\Errors\ApiExceptionRenderer;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function (): void {
            // Each area is a separate file with its own guard, URL prefix
            // and route-name prefix, so a route cannot end up on the wrong
            // guard by being declared in the wrong place.
            Route::middleware('web')
                ->prefix('admin')
                ->name('admin.')
                ->group(base_path('routes/admin.php'));

            Route::middleware('web')
                ->name('client.')
                ->group(base_path('routes/client.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Correlation must be assigned before anything else can log, so that
        // no line written while handling the request is orphaned.
        $middleware->prepend(AssignCorrelationId::class);

        // The organization boundary is established from the authenticated
        // actor before any handler runs, so no query can accidentally execute
        // unscoped.
        $middleware->web(append: [
            ResolveOrganizationContext::class,
            TrackAuthenticatedSession::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);

        // Ordering matters more than it looks. Route-model binding runs
        // inside SubstituteBindings, and a binding resolved before the
        // boundary exists is an unscoped query: it would hand a reseller
        // another reseller's record by id and leave the policy as the only
        // thing standing between them. The boundary is therefore forced
        // ahead of binding rather than merely appended to the group.
        $middleware->prependToPriorityList(
            before: SubstituteBindings::class,
            prepend: ResolveOrganizationContext::class,
        );

        $middleware->api(append: [
            ResolveOrganizationContext::class,
        ]);

        $middleware->alias([
            'impersonation.blocked' => BlockDuringImpersonation::class,
        ]);

        $middleware->redirectGuestsTo(
            fn (Request $request): string => $request->is('admin', 'admin/*') ? '/admin/login' : '/login',
        );

        // A signed-in visitor hitting a sign-in screen goes to their own
        // area rather than being shown a form they cannot use.
        $middleware->redirectUsersTo(
            fn (Request $request): string => $request->is('admin', 'admin/*') ? '/admin' : '/client',
        );

        // SPA requests from the first-party admin/client apps authenticate
        // with the session cookie; third-party integrations use bearer
        // tokens. Sanctum's stateful guard handles both on the same routes.
        $middleware->statefulApi();

        $proxies = env('TRUSTED_PROXIES');

        if (is_string($proxies) && $proxies !== '') {
            $middleware->trustProxies(at: $proxies === '*' ? '*' : explode(',', $proxies));
        }
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request): bool => $request->is('api/*') || $request->expectsJson(),
        );

        // Correlation identifier on every reported exception, so a log entry
        // can be tied back to the request the customer complained about.
        $exceptions->context(fn (): array => [
            'correlation_id' => app(CorrelationContext::class)->id(),
        ]);

        $exceptions->render(function (Throwable $e, Request $request) {
            if (! $request->is('api/*') && ! $request->expectsJson()) {
                return null;
            }

            return app(ApiExceptionRenderer::class)->render($e);
        });
    })
    ->create();
