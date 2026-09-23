<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Licensing\Contracts\Entitlements;
use App\Http\Middleware\AssignCorrelationId;
use App\Infrastructure\Audit\DatabaseAuditRecorder;
use App\Infrastructure\Licensing\UnrestrictedEntitlements;
use App\Support\Audit\Contracts\AuditRecorder;
use App\Support\Branding\StorefrontComposer;
use App\Support\Correlation\CorrelationContext;
use App\Support\Errors\ApiExceptionRenderer;
use App\Support\Logging\SecretRedactor;
use App\Support\View\BladeStorefrontRenderer;
use App\Support\View\StorefrontRenderer;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use Psr\Http\Message\RequestInterface;

/**
 * Wires the cross-cutting platform services: correlation identifiers,
 * redaction, the error envelope, the audit trail and the storefront renderer
 * abstraction.
 */
final class PlatformServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(SecretRedactor::class, function (): SecretRedactor {
            /** @var list<string> $keys */
            $keys = config('platform.redaction.keys', []);

            return new SecretRedactor(
                keys: $keys,
                placeholder: (string) config('platform.redaction.placeholder', '[redacted]'),
                maxDepth: (int) config('platform.redaction.max_depth', 16),
                redactCardLikeValues: (bool) config('platform.redaction.redact_card_like_values', true),
            );
        });

        $this->app->singleton(CorrelationContext::class, fn (): CorrelationContext => new CorrelationContext(
            (string) config('platform.correlation.context_key', 'correlation_id'),
        ));

        $this->app->singleton(ApiExceptionRenderer::class, fn (): ApiExceptionRenderer => new ApiExceptionRenderer(
            correlation: $this->app->make(CorrelationContext::class),
            exposeInternalMessages: ! $this->app->isProduction(),
        ));

        // Bound, not shared: the middleware reads configuration at
        // construction time and must see a change made inside a request.
        $this->app->bind(AssignCorrelationId::class, fn (): AssignCorrelationId => new AssignCorrelationId(
            context: $this->app->make(CorrelationContext::class),
            header: (string) config('platform.correlation.header', 'X-Correlation-Id'),
            trustInbound: (bool) config('platform.correlation.trust_inbound', false),
        ));

        $this->app->singleton(AuditRecorder::class, DatabaseAuditRecorder::class);

        $this->app->bind(StorefrontRenderer::class, BladeStorefrontRenderer::class);

        // A self-hosted installation with no licence server must not be
        // crippled by a check it cannot answer. A commercial
        // distribution binds something else.
        $this->app->bind(Entitlements::class, UnrestrictedEntitlements::class);
    }

    public function boot(): void
    {
        // The core theme is the fallback of last resort. The active
        // theme's own chain replaces this namespace per request, once the
        // organization — and therefore whose storefront this is — is known.
        $this->loadViewsFrom(base_path('themes/storefront/core/views'), 'storefront');

        // Every storefront template gets the brand, whoever rendered it. A
        // controller that had to remember would eventually not.
        View::composer('storefront::*', StorefrontComposer::class);

        $this->propagateCorrelationIdToOutboundRequests();
        $this->assignCorrelationIdToScheduledTasks();
    }

    /**
     * Provider calls carry our correlation identifier so that a support
     * conversation with a registrar or a gateway can be anchored to the same
     * string the customer was shown.
     */
    private function propagateCorrelationIdToOutboundRequests(): void
    {
        $header = (string) config('platform.correlation.header', 'X-Correlation-Id');

        Http::globalRequestMiddleware(function (RequestInterface $request) use ($header): RequestInterface {
            $id = $this->app->make(CorrelationContext::class)->id();

            return $id === null ? $request : $request->withHeader($header, $id);
        });
    }

    /**
     * A scheduled run is its own unit of work: give it a fresh identifier so
     * its log lines and audit records group together.
     */
    private function assignCorrelationIdToScheduledTasks(): void
    {
        Event::listen(ScheduledTaskStarting::class, function (): void {
            $context = $this->app->make(CorrelationContext::class);
            $context->forget();
            $context->idOrGenerate();
        });
    }
}
