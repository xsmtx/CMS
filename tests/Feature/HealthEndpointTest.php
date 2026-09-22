<?php

declare(strict_types=1);

use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as RouteFacade;

it('reports dependency health', function (): void {
    $this->getJson('/api/v1/health')
        ->assertOk()
        ->assertJsonPath('status', 'ok')
        ->assertJsonPath('checks.database.healthy', true)
        ->assertJsonStructure(['status', 'checks', 'request_id', 'time']);
});

it('is rate limited so it cannot be used to probe the stack', function (): void {
    $route = collect(RouteFacade::getRoutes()->getRoutes())
        ->first(fn (Route $route): bool => $route->getName() === 'api.v1.health');

    expect($route)->not->toBeNull()
        ->and($route->gatherMiddleware())->toContain('throttle:60,1');
});
