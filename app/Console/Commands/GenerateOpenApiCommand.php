<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Api\ApiScope;
use App\Support\Errors\ErrorCode;
use Illuminate\Console\Command;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

/**
 * Writes `docs/api/openapi.json` from the routes themselves.
 *
 * **Generated, never hand-written.** A specification maintained by hand is
 * a specification that is wrong by the second release, and a wrong
 * specification is worse than none: an integrator writes code against it
 * and blames their own bug for a day. A test regenerates this and fails if
 * the committed copy differs, so the document cannot drift from the routes
 * without somebody being told.
 *
 * What it takes from the routes is what the routes actually know: the path,
 * the method, the name, and the scope the middleware demands. Descriptions
 * come from a small map here rather than from annotations, because a
 * docblock that has to be parsed is a second source of truth pretending to
 * be one.
 */
final class GenerateOpenApiCommand extends Command
{
    protected $signature = 'platform:openapi {--check : Fail if the committed document is out of date}';

    protected $description = 'Generate the OpenAPI document for /api/v1';

    public function handle(): int
    {
        $document = json_encode(
            $this->document(),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        )."\n";

        $path = base_path('docs/api/openapi.json');

        if ($this->option('check')) {
            $committed = is_file($path) ? (string) file_get_contents($path) : '';

            if ($committed !== $document) {
                $this->components->error(
                    'docs/api/openapi.json is out of date. Run `php artisan platform:openapi`.',
                );

                return self::FAILURE;
            }

            $this->components->info('The OpenAPI document matches the routes.');

            return self::SUCCESS;
        }

        file_put_contents($path, $document);

        $this->components->info('Wrote docs/api/openapi.json.');

        return self::SUCCESS;
    }

    /**
     * @return array<string, mixed>
     */
    private function document(): array
    {
        $paths = [];

        foreach ($this->apiRoutes() as $route) {
            $path = '/'.ltrim((string) $route->uri(), '/');
            $path = (string) preg_replace('/\{(\w+)\??\}/', '{$1}', $path);

            foreach ($route->methods() as $method) {
                if (in_array($method, ['HEAD', 'OPTIONS'], true)) {
                    continue;
                }

                $paths[$path][strtolower($method)] = $this->operation($route, $method, $path);
            }
        }

        ksort($paths);

        return [
            'openapi' => '3.1.0',
            'info' => [
                'title' => 'InfraCMS API',
                'version' => '1',
                'description' => 'Every error uses one envelope; see docs/api/errors.md. '
                    .'Money is always an integer in minor units with an ISO 4217 code, '
                    .'never a float and never a formatted string.',
            ],
            'servers' => [['url' => '/']],
            'components' => [
                'securitySchemes' => [
                    'bearer' => ['type' => 'http', 'scheme' => 'bearer'],
                ],
                'schemas' => [
                    'Money' => [
                        'type' => 'object',
                        'required' => ['amount', 'currency'],
                        'properties' => [
                            'amount' => [
                                'type' => 'integer',
                                'description' => 'Minor units. 1499 is 14.99 in a two-decimal currency.',
                            ],
                            'currency' => ['type' => 'string', 'example' => 'EUR'],
                        ],
                    ],
                    'Error' => [
                        'type' => 'object',
                        'required' => ['error'],
                        'properties' => [
                            'error' => [
                                'type' => 'object',
                                'required' => ['code', 'message', 'details', 'request_id'],
                                'properties' => [
                                    'code' => [
                                        'type' => 'string',
                                        'enum' => array_column(ErrorCode::cases(), 'value'),
                                    ],
                                    'message' => ['type' => 'string'],
                                    'details' => ['type' => 'object'],
                                    'request_id' => ['type' => ['string', 'null']],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            'security' => [['bearer' => []]],
            'paths' => $paths,
            'x-scopes' => array_map(
                static fn (ApiScope $scope): array => [
                    'scope' => $scope->value,
                    'requires' => $scope->requiredPermissions(),
                ],
                ApiScope::cases(),
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function operation(RoutingRoute $route, string $method, string $path): array
    {
        $scope = $this->scopeOf($route);
        $name = (string) $route->getName();

        $operation = [
            'operationId' => Str::camel(str_replace(['api.v1.', '.'], ['', '_'], $name)),
            'tags' => [$this->tagOf($path)],
            'summary' => $this->summaryOf($name),
            'parameters' => $this->parameters($route, $method),
            'responses' => [
                (string) $this->successStatus($method) => ['description' => 'Success'],
                '401' => $this->errorResponse('No valid token.'),
                '403' => $this->errorResponse('Out of scope, or not permitted.'),
                '422' => $this->errorResponse('The input was rejected.'),
                '429' => $this->errorResponse('Rate limited. Honour Retry-After.'),
            ],
        ];

        if ($scope !== null) {
            $operation['security'] = [['bearer' => [$scope]]];
        }

        if (in_array($method, ['POST', 'PUT', 'PATCH'], true)) {
            $operation['responses']['409'] = $this->errorResponse(
                'This idempotency key was already used for a different request.',
            );
        }

        return $operation;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function parameters(RoutingRoute $route, string $method): array
    {
        $parameters = [];

        foreach ($route->parameterNames() as $parameter) {
            $parameters[] = [
                'name' => $parameter,
                'in' => 'path',
                'required' => true,
                'schema' => ['type' => 'string'],
            ];
        }

        if ($method === 'GET' && str_ends_with((string) $route->getName(), '.index')) {
            $parameters[] = [
                'name' => 'page',
                'in' => 'query',
                'schema' => ['type' => 'integer', 'minimum' => 1],
            ];
            $parameters[] = [
                'name' => 'per_page',
                'in' => 'query',
                'schema' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100],
            ];
            $parameters[] = [
                'name' => 'sort',
                'in' => 'query',
                'description' => 'A declared field, prefixed with - to reverse it. '
                    .'An undeclared field is a 422, never silence.',
                'schema' => ['type' => 'string'],
            ];
        }

        if ($method !== 'GET') {
            $parameters[] = [
                'name' => 'Idempotency-Key',
                'in' => 'header',
                'required' => false,
                'description' => 'Send the same key to retry safely. The original response '
                    .'is replayed verbatim; a different payload under the same key is a 409.',
                'schema' => ['type' => 'string'],
            ];
        }

        return $parameters;
    }

    /**
     * @return array<string, mixed>
     */
    private function errorResponse(string $description): array
    {
        return [
            'description' => $description,
            'content' => [
                'application/json' => ['schema' => ['$ref' => '#/components/schemas/Error']],
            ],
        ];
    }

    private function successStatus(string $method): int
    {
        return match ($method) {
            'POST' => 201,
            'DELETE' => 204,
            default => 200,
        };
    }

    private function scopeOf(RoutingRoute $route): ?string
    {
        foreach ($route->gatherMiddleware() as $middleware) {
            if (! is_string($middleware) || ! str_contains($middleware, 'RequireApiScope:')) {
                continue;
            }

            return Str::after($middleware, 'RequireApiScope:');
        }

        return null;
    }

    private function tagOf(string $path): string
    {
        $segments = array_values(array_filter(explode('/', $path)));

        return $segments[2] ?? 'api';
    }

    private function summaryOf(string $name): string
    {
        return Str::of($name)
            ->after('api.v1.')
            ->replace('.', ' ')
            ->replace('_', ' ')
            ->ucfirst()
            ->toString();
    }

    /**
     * @return list<RoutingRoute>
     */
    private function apiRoutes(): array
    {
        $routes = [];

        /** @var list<RoutingRoute> $all */
        $all = Route::getRoutes()->getRoutes();

        foreach ($all as $route) {
            $name = $route->getName();

            // The health endpoint is in, the gateway callbacks are not:
            // those are a provider's contract with us, not ours with an
            // integrator.
            if (is_string($name) && str_starts_with($name, 'api.v1.')) {
                $routes[] = $route;
            }
        }

        usort($routes, static fn (RoutingRoute $a, RoutingRoute $b): int => $a->uri() <=> $b->uri());

        return $routes;
    }
}
