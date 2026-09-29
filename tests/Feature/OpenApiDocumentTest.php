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

    /*
     * The four endpoints that carry no scope, named one by one with the
     * reason. A list rather than a rule on purpose: an exemption that
     * matches a *shape* is an exemption everything eventually matches, and
     * a scope-free endpoint is exactly what somebody would add by accident.
     */
    $unscoped = [
        // A monitoring system needs to know this installation is alive
        // without holding a credential for it.
        '/api/v1/health',
        // These two *are* the authentication (ADR 0049): the first takes a
        // password and the second takes a refresh token, and each is the
        // credential. The third is authenticated and scope-free, because a
        // token being able to end itself is not a privilege — and one that
        // could not would leave an application no way to sign out.
        '/api/v1/auth/token',
        '/api/v1/auth/refresh',
    ];

    $scoped = 0;

    foreach ($paths as $path => $operations) {
        foreach ($operations as $operation) {
            if (in_array($path, $unscoped, strict: true)) {
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
