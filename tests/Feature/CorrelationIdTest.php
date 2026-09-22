<?php

declare(strict_types=1);

use App\Support\Correlation\CorrelationContext;

it('returns a correlation identifier on every response', function (): void {
    $response = $this->getJson('/api/v1/health');

    $header = $response->headers->get('X-Correlation-Id');

    expect($header)->not->toBeNull()->and($header)->toHaveLength(26);
});

it('reports the same identifier in the body and the header', function (): void {
    $response = $this->getJson('/api/v1/health');

    expect($response->json('request_id'))
        ->toBe($response->headers->get('X-Correlation-Id'));
});

it('ignores an inbound identifier when inbound headers are not trusted', function (): void {
    config()->set('platform.correlation.trust_inbound', false);

    $response = $this->withHeader('X-Correlation-Id', '01K5Q8ZP4F8T7M0QX9J0K3N2VB')
        ->getJson('/api/v1/health');

    expect($response->headers->get('X-Correlation-Id'))
        ->not->toBe('01K5Q8ZP4F8T7M0QX9J0K3N2VB');
});

it('honours an inbound identifier behind a trusted proxy', function (): void {
    config()->set('platform.correlation.trust_inbound', true);

    $response = $this->withHeader('X-Correlation-Id', '01K5Q8ZP4F8T7M0QX9J0K3N2VB')
        ->getJson('/api/v1/health');

    expect($response->headers->get('X-Correlation-Id'))->toBe('01K5Q8ZP4F8T7M0QX9J0K3N2VB');
});

it('discards a malformed inbound identifier even when trusted', function (): void {
    config()->set('platform.correlation.trust_inbound', true);

    $response = $this->withHeader('X-Correlation-Id', "bogus\nlog injection")
        ->getJson('/api/v1/health');

    expect($response->headers->get('X-Correlation-Id'))->toHaveLength(26);
});

it('generates an identifier for work that did not start as a request', function (): void {
    $context = app(CorrelationContext::class);
    $context->forget();

    $first = $context->idOrGenerate();

    expect($first)->toHaveLength(26)
        ->and($context->idOrGenerate())->toBe($first);
});
