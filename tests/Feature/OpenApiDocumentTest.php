<?php

declare(strict_types=1);

use App\Domain\Api\ApiScope;

/**
 * The specification is generated from the routes, and this is what stops it
 * drifting from them. A hand-maintained specification is wrong by the
 * second release, and a wrong one is worse than none: an integrator writes
 * code against it and spends a day blaming their own bug.
 */
it('has a committed OpenAPI document that matches the routes', function (): void {
    $this->artisan('platform:openapi --check')->assertSuccessful();
});

it('documents every route with the scope its middleware demands', function (): void {
    $document = json_decode(
        (string) file_get_contents(base_path('docs/api/openapi.json')),
        associative: true,
    );

    /** @var array<string, array<string, mixed>> $paths */
    $paths = $document['paths'];

    $scoped = 0;

    foreach ($paths as $path => $operations) {
        foreach ($operations as $operation) {
            if ($path === '/api/v1/health') {
                // Unauthenticated on purpose: a monitoring system needs to
                // know the installation is alive without holding a
                // credential for it.
                continue;
            }

            $scopes = $operation['security'][0]['bearer'] ?? [];

            expect($scopes)->toHaveCount(1)
                ->and(ApiScope::tryFrom($scopes[0]))->toBeInstanceOf(ApiScope::class);

            $scoped++;
        }
    }

    expect($scoped)->toBeGreaterThan(15);
});

it('tells an integrator about the idempotency header on every write', function (): void {
    $document = json_decode(
        (string) file_get_contents(base_path('docs/api/openapi.json')),
        associative: true,
    );

    /** @var array<string, array<string, mixed>> $paths */
    $paths = $document['paths'];

    foreach ($paths as $operations) {
        foreach ($operations as $method => $operation) {
            if ($method === 'get') {
                continue;
            }

            $names = array_column($operation['parameters'] ?? [], 'name');

            expect($names)->toContain('Idempotency-Key');
        }
    }
});
