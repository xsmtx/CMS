<?php

declare(strict_types=1);

/**
 * Executable enforcement of the layering rules in docs/adr/0001.
 *
 * A dependency-direction violation is a review comment that is easy to miss
 * and expensive to unwind later, so it fails the build instead.
 */
arch('the domain layer stays framework free')
    ->expect('App\Domain')
    ->not->toUse([
        'Illuminate',
        'Inertia',
        'App\Http',
        'App\Infrastructure',
        'App\Application',
    ]);

arch('the application layer does not depend on the interface layer')
    ->expect('App\Application')
    ->not->toUse(['App\Http', 'Inertia']);

arch('infrastructure does not depend on the interface layer')
    ->expect('App\Infrastructure')
    ->not->toUse(['App\Http', 'Inertia']);

arch('controllers do not talk to provider SDKs or the database directly')
    ->expect('App\Http\Controllers')
    ->not->toUse([
        'Illuminate\Support\Facades\DB',
        'Illuminate\Database\Eloquent\Builder',
    ])
    ->ignoring('App\Http\Controllers\Api\V1\HealthController');

arch('debug helpers never reach the repository')
    ->expect(['dd', 'dump', 'var_dump', 'ray', 'print_r', 'die'])
    ->not->toBeUsed();

arch('everything declares strict types')
    ->expect('App')
    ->toUseStrictTypes();

arch('domain objects are final or abstract')
    ->expect('App\Domain')
    ->classes()
    ->toBeFinal()
    ->ignoring([
        'App\Domain\Access\Exceptions',
        // The one class in the domain that exists to be subclassed, and by
        // code this repository does not contain: a module author extends
        // it so that a package providing one thing writes one method.
        // `final` would make the SDK unusable.
        'App\Domain\Modules\BaseModule',
    ]);

arch('enums live where they are declared and are backed')
    ->expect('App\Domain\Access\RoleScope')
    ->toBeStringBackedEnum();

arch('tests never reach a real payment or provisioning provider')
    ->expect([
        'Stripe',
        'PayPal',
        'GuzzleHttp\Client',
    ])
    ->not->toBeUsedIn('Tests');
