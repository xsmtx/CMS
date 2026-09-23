<?php

declare(strict_types=1);

namespace App\Providers;

use App\Application\Licensing\LicencePublicKey;
use App\Domain\Licensing\Contracts\Entitlements;
use App\Domain\Licensing\Contracts\LicenceClient;
use App\Http\Middleware\AssignCorrelationId;
use App\Infrastructure\Audit\DatabaseAuditRecorder;
use App\Infrastructure\Licensing\HttpLicenceClient;
use App\Infrastructure\Licensing\LicensedEntitlements;
use App\Infrastructure\Licensing\UnconfiguredLicenceClient;
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

        $this->bindLicensing();
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
     * The licence client, and what it entitles this installation to.
     *
     * **Which `Entitlements` is bound depends on configuration, not on
     * whether a licence is live.** An installation with a licence API
     * configured gets `LicensedEntitlements`, which then answers from the
     * stored state — including "no licence has ever been activated", which
     * allows everything. An installation with nothing configured gets the
     * unrestricted binding and never reads a state row at all.
     *
     * The distinction matters because a self-hosted installation with no
     * commercial relationship must not be crippled by a check it has no way to
     * answer, and a gate whose default is deny turns an unreachable licence
     * API into an outage.
     *
     * `scoped`, not `singleton`: the state is cached for the length of a
     * request, because `allows()` is called from render paths and a query per
     * call would be a query per rendered badge — but a queue worker handling
     * two jobs must not carry the first job's answer into the second.
     */
    private function bindLicensing(): void
    {
        $this->app->singleton(LicencePublicKey::class);

        $this->app->bind(LicenceClient::class, function (): LicenceClient {
            $url = config('platform.licensing.api_url');

            if (! is_string($url) || trim($url) === '') {
                // Nothing configured. A client that throws on every call is
                // the honest binding: the alternative is a null object that
                // returns a token nobody signed.
                return new UnconfiguredLicenceClient;
            }

            return new HttpLicenceClient(
                baseUrl: trim($url),
                correlation: $this->app->make(CorrelationContext::class),
                timeout: (int) config('platform.licensing.timeout', 10),
                retries: (int) config('platform.licensing.retries', 2),
            );
        });

        $this->app->scoped(Entitlements::class, function (): Entitlements {
            $url = config('platform.licensing.api_url');

            return is_string($url) && trim($url) !== ''
                ? new LicensedEntitlements
                : new UnrestrictedEntitlements;
        });
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
