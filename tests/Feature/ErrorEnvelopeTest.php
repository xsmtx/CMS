<?php

declare(strict_types=1);

use App\Support\Correlation\CorrelationContext;
use App\Support\Errors\ApiExceptionRenderer;
use App\Support\Errors\ErrorCode;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

it('renders validation failures in the platform envelope', function (): void {
    Route::middleware('api')->post('/api/v1/_test/validate', function (): void {
        throw ValidationException::withMessages(['email' => 'The email field is required.']);
    });

    $this->postJson('/api/v1/_test/validate')
        ->assertStatus(422)
        ->assertJsonPath('error.code', ErrorCode::ValidationFailed->value)
        ->assertJsonPath('error.details.email.0', 'The email field is required.')
        ->assertJsonStructure(['error' => ['code', 'message', 'details', 'request_id']]);
});

it('renders a missing route as a not_found envelope', function (): void {
    $this->getJson('/api/v1/does-not-exist')
        ->assertStatus(404)
        ->assertJsonPath('error.code', ErrorCode::NotFound->value);
});

it('renders an explicit http exception with the matching code', function (): void {
    Route::middleware('api')->get('/api/v1/_test/gone', function (): void {
        throw new NotFoundHttpException;
    });

    $this->getJson('/api/v1/_test/gone')
        ->assertStatus(404)
        ->assertJsonPath('error.code', ErrorCode::NotFound->value);
});

it('includes the correlation identifier as the request id', function (): void {
    $response = $this->getJson('/api/v1/does-not-exist');

    expect($response->json('error.request_id'))
        ->toBe($response->headers->get('X-Correlation-Id'));
});

it('does not leak internal exception messages when messages are not exposed', function (): void {
    Route::middleware('api')->get('/api/v1/_test/boom', function (): void {
        throw new RuntimeException('Connection string: user:pa55word@db');
    });

    $this->app->bind(ApiExceptionRenderer::class, fn ($app): ApiExceptionRenderer => new ApiExceptionRenderer(
        $app->make(CorrelationContext::class),
        exposeInternalMessages: false,
    ));

    $response = $this->getJson('/api/v1/_test/boom');

    expect($response->json('error.message'))->not->toContain('pa55word')
        ->and($response->json('error.code'))->toBe(ErrorCode::ServerError->value);
});

it('leaves html requests to the framework renderer', function (): void {
    $response = $this->get('/definitely-not-a-route');

    expect($response->getStatusCode())->toBe(404)
        ->and($response->headers->get('Content-Type'))->toContain('text/html');
});
