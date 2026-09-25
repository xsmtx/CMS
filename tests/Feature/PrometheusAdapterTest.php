<?php

declare(strict_types=1);

use App\Domain\Health\HealthState;
use App\Domain\Infrastructure\Capability;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use InfraCMS\MonitoringPrometheus\PrometheusProvider;

/**
 * The first monitoring adapter that reads a real system
 * (`advanced-operations-plan.md`, Phase B).
 *
 * It has never talked to a real Prometheus, and these tests do not pretend
 * otherwise: they prove the request shapes, the parsing and the refusals
 * against faked HTTP, which is the code and not the integration. What they
 * are really for is the three conventions every one of the twenty-three
 * adapter families has to follow — raw names and units, a partial answer as a
 * normal answer, and a health message that says nothing about configuration.
 */
beforeEach(function (): void {
    loadModuleClasses('monitoring-prometheus');

    $this->provider = fn (?string $token = null): PrometheusProvider => new PrometheusProvider(
        baseUrl: 'https://prometheus.test',
        token: static fn (): ?string => $token,
        instanceLabel: 'instance',
        staleAfter: 120,
        timeout: 5,
    );
});

function promResult(string $instance, string $value, int $at = 1_700_000_000): array
{
    return [
        'metric' => ['instance' => $instance],
        'value' => [$at, $value],
    ];
}

it('declares that it only reads', function (): void {
    $capabilities = ($this->provider)()->capabilities();

    expect($capabilities->has(Capability::MetricsRead))->toBeTrue()
        ->and($capabilities->has(Capability::AlertsWrite))->toBeFalse();
});

it('reads the present value of each series for the targets it was given', function (): void {
    Http::fake([
        'prometheus.test/api/v1/query*' => Http::response([
            'status' => 'success',
            'data' => ['result' => [promResult('web1.example.test', '0.42')]],
        ]),
    ]);

    $batch = ($this->provider)()->collect(['web1.example.test']);

    expect($batch->samples)->not->toBeEmpty()
        ->and($batch->unknownTargets)->toBe([]);

    $first = $batch->samples[0];

    // The raw name and the raw unit: `MetricKind` does the naming, not the
    // adapter.
    expect($first->target)->toBe('web1.example.test')
        ->and($first->name)->toBe('cpu_utilisation')
        ->and($first->unit)->toBe('ratio')
        ->and($first->value)->toBe(0.42)
        ->and($first->staleAfterSeconds)->toBe(120);
});

/**
 * Nine of ten is not success and must not look like it.
 */
it('names the targets Prometheus said nothing about', function (): void {
    Http::fake([
        'prometheus.test/api/v1/query*' => Http::response([
            'status' => 'success',
            'data' => ['result' => [promResult('web1.example.test', '0.42')]],
        ]),
    ]);

    $batch = ($this->provider)()->collect(['web1.example.test', 'db1.example.test']);

    expect($batch->unknownTargets)->toBe(['db1.example.test']);
});

/**
 * A node key is a hostname somebody typed, and a hostname is full of dots.
 * Unquoted, `db1.example.test` matches `db1xexample.test` as well — which is
 * a reading attached to the wrong machine, and nothing would ever say so.
 */
it('quotes a target into the query rather than pasting it', function (): void {
    Http::fake([
        'prometheus.test/api/v1/query*' => Http::response(['status' => 'success', 'data' => ['result' => []]]),
    ]);

    ($this->provider)()->collect(['db1.example.test']);

    Http::assertSent(function ($request): bool {
        $query = urldecode((string) parse_url($request->url(), PHP_URL_QUERY));

        return str_contains($query, 'db1\.example\.test');
    });
});

it('sends the credential as a bearer token when there is one', function (): void {
    Http::fake([
        'prometheus.test/*' => Http::response(['status' => 'success', 'data' => ['result' => []]]),
    ]);

    ($this->provider)('a-secret-token')->collect(['web1.example.test']);

    Http::assertSent(fn ($request): bool => $request->hasHeader('Authorization', 'Bearer a-secret-token'));
});

it('asks without a credential when none is configured', function (): void {
    Http::fake([
        'prometheus.test/*' => Http::response(['status' => 'success', 'data' => ['result' => []]]),
    ]);

    ($this->provider)()->collect(['web1.example.test']);

    Http::assertSent(fn ($request): bool => ! $request->hasHeader('Authorization'));
});

it('reports a version when Prometheus answers', function (): void {
    Http::fake([
        'prometheus.test/api/v1/status/buildinfo' => Http::response([
            'status' => 'success',
            'data' => ['version' => '2.53.0'],
        ]),
    ]);

    $health = ($this->provider)()->health();

    expect($health->state)->toBe(HealthState::Ok)
        ->and($health->remoteVersion)->toBe('2.53.0');
});

/**
 * The Phase 9 rule, which every adapter inherits: a health message is
 * rendered on a screen and written to a log, so it says nothing about
 * configuration — not the address, not the credential, not a prefix of
 * either.
 */
it('never puts the address or the credential in a health message', function (): void {
    Http::fake(['prometheus.test/*' => Http::response('nope', 401)]);

    $health = ($this->provider)('a-secret-token')->health();

    expect($health->state)->toBe(HealthState::Failing)
        ->and($health->message)->not->toContain('prometheus.test')
        ->and($health->message)->not->toContain('a-secret-token')
        ->and($health->message)->toContain('credential');
});

it('says it could not be reached rather than throwing', function (): void {
    Http::fake(fn () => throw new ConnectionException('down'));

    $health = ($this->provider)()->health();
    $batch = ($this->provider)()->collect(['web1.example.test']);

    expect($health->state)->toBe(HealthState::Failing)
        ->and($batch->samples)->toBe([])
        ->and($batch->note)->not->toBeNull();
});

/**
 * Prometheus answers a value as a string so NaN and Inf survive JSON. A
 * reading that is not a number is one this adapter does not report, rather
 * than a zero it invents — a graph showing zero CPU is a graph an operator
 * acts on.
 */
it('drops a reading that is not a number instead of inventing one', function (): void {
    Http::fake([
        'prometheus.test/api/v1/query*' => Http::response([
            'status' => 'success',
            'data' => ['result' => [promResult('web1.example.test', 'NaN')]],
        ]),
    ]);

    $batch = ($this->provider)()->collect(['web1.example.test']);

    expect($batch->samples)->toBe([])
        ->and($batch->unknownTargets)->toBe(['web1.example.test']);
});
