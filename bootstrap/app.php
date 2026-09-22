<?php

declare(strict_types=1);

use App\Http\Middleware\AssignCorrelationId;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\ResolveOrganizationContext;
use App\Support\Correlation\CorrelationContext;
use App\Support\Errors\ApiExceptionRenderer;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
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
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);

        $middleware->api(append: [
            ResolveOrganizationContext::class,
        ]);

        // Phase 1 implements the sign-in screens at these paths. Until then a
        // guest is still redirected here rather than to a route name that
        // does not exist, so the middleware contract is already correct.
        $middleware->redirectGuestsTo(
            fn (Request $request): string => $request->is('admin', 'admin/*') ? '/admin/login' : '/login',
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
