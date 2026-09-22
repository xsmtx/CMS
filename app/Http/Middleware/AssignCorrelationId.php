<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\Correlation\CorrelationContext;
use App\Support\Correlation\CorrelationId;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * First middleware in the global stack: every request leaves with a
 * correlation identifier, and every log line written while handling it
 * carries the same value.
 */
final readonly class AssignCorrelationId
{
    public function __construct(
        private CorrelationContext $context,
        private string $header,
        private bool $trustInbound,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $id = $this->resolve($request);

        $this->context->set($id);
        $request->headers->set($this->header, $id->value);
        $request->attributes->set('correlation_id', $id->value);

        /** @var Response $response */
        $response = $next($request);

        $response->headers->set($this->header, $id->value);

        return $response;
    }

    private function resolve(Request $request): CorrelationId
    {
        if (! $this->trustInbound) {
            return CorrelationId::generate();
        }

        $inbound = $request->headers->get($this->header);

        return CorrelationId::tryFrom(is_string($inbound) ? $inbound : null)
            ?? CorrelationId::generate();
    }
}
