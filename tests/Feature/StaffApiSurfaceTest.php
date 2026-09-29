<?php

declare(strict_types=1);

use App\Domain\Api\ApiScope;
use App\Domain\Api\StaffApiScope;
use App\Http\Middleware\AuthenticateStaffApiToken;
use App\Http\Middleware\RequireStaffApiScope;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;

/**
 * What the staff API is, and — more importantly — what it is not (ADR 0049).
 *
 * §26 says some actions are web-only by policy. A client can be rewritten,
 * so leaving a button out of an application enforces nothing: the refusal has
 * to be **the absence of the endpoint**, and this file is what keeps it
 * absent. Somebody adding a power action to the staff API in six months
 * discovers the decision here rather than after it ships.
 *
 * It is a list of forbidden words rather than a list of allowed routes on
 * purpose. An allow-list would need editing for every ordinary read somebody
 * adds, so it would be edited without thought — and the one edit that
 * mattered would go through with the rest.
 */
function staffRoutes(): array
{
    return array_values(array_filter(
        Route::getRoutes()->getRoutes(),
        static fn (RoutingRoute $route): bool => str_starts_with((string) $route->uri(), 'api/v1/staff'),
    ));
}

it('has a staff surface at all', function (): void {
    expect(staffRoutes())->not->toBeEmpty();
});

/**
 * The six words §26 names, plus the two this product added afterwards.
 */
it('offers no route for anything that is web-only by policy', function (): void {
    $forbidden = [
        // §26, verbatim: firewall config, device reboot, service
        // termination, restore, mass actions, physical power control.
        'firewall',
        'power',
        'terminate',
        'restore',
        'bulk',
        'reboot',
        // Added by phases F and C, and dangerous for the same reason: a
        // drain takes a machine out of rotation and an apply pushes a
        // configuration to a box.
        'drain',
        'changes/apply',
    ];

    foreach (staffRoutes() as $route) {
        foreach ($forbidden as $word) {
            expect((string) $route->uri())->not->toContain($word);
        }
    }
});

/**
 * There is no `*`, and there must not be: the whole problem is that an
 * Administrator already holds every staff permission by design.
 */
it('has no staff scope that means everything', function (): void {
    foreach (StaffApiScope::cases() as $scope) {
        expect($scope->value)->not->toBe('*')
            ->and($scope->requiredPermissions())->not->toBeEmpty();
    }
});

it('puts every staff route behind both the guard and a scope', function (): void {
    $unscoped = 0;

    foreach (staffRoutes() as $route) {
        $middleware = $route->gatherMiddleware();

        // The authentication endpoints are the exception, named here rather
        // than matched by a shape: they *are* the authentication, and
        // signing out is scope-free because a token ending itself is not a
        // privilege.
        if (in_array($route->getName(), [
            'api.v1.staff.auth.token',
            'api.v1.staff.auth.refresh',
        ], strict: true)) {
            $unscoped++;

            continue;
        }

        expect($middleware)->toContain(AuthenticateStaffApiToken::class);

        if ($route->getName() === 'api.v1.staff.auth.revoke') {
            $unscoped++;

            continue;
        }

        $scoped = array_filter(
            $middleware,
            static fn (string $entry): bool => str_starts_with($entry, RequireStaffApiScope::class.':'),
        );

        expect($scoped)->toHaveCount(1);

        // And the scope it names has to be a real one, so a typo fails here
        // rather than at three in the morning.
        $declared = explode(':', (string) array_values($scoped)[0], 2)[1];

        expect(StaffApiScope::tryFrom($declared))->toBeInstanceOf(StaffApiScope::class);
    }

    // The guard on the guard: three exemptions and no more. An audit that
    // examined nothing would pass.
    expect($unscoped)->toBe(3);
});

/**
 * A staff scope never reaches the client surface and vice versa. They are
 * separate enums for this reason, and this is what says so.
 */
it('keeps the two scope vocabularies apart', function (): void {
    $client = array_column(ApiScope::cases(), 'value');
    $staff = StaffApiScope::values();

    $shared = array_intersect($client, $staff);

    // `tickets:read` is in both, and means two different things — which is
    // exactly why one enum holding both would be a bug waiting to be
    // written.
    foreach ($shared as $value) {
        expect(ApiScope::from($value)->requiredPermissions())
            ->not->toBe(StaffApiScope::from($value)->requiredPermissions());
    }
});
