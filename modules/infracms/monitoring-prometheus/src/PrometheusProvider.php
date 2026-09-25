<?php

declare(strict_types=1);

namespace InfraCMS\MonitoringPrometheus;

use App\Domain\Health\HealthState;
use App\Domain\Infrastructure\AdapterHealth;
use App\Domain\Infrastructure\Capability;
use App\Domain\Infrastructure\CapabilitySet;
use App\Domain\Infrastructure\Contracts\MonitoringProvider;
use App\Domain\Infrastructure\RateLimits;
use App\Domain\Infrastructure\RawSample;
use App\Domain\Infrastructure\SampleBatch;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * What Prometheus already knows, attached to what this platform knows.
 *
 * The platform is not a monitoring system and this adapter is the proof:
 * Prometheus keeps the series, this reads the **present** value of four of
 * them and hands it over so the graph can say which customer notices when
 * that machine stops (`advanced-operations-plan.md` §1).
 *
 * **Four queries, not a scrape.** An instant query per metric across every
 * target, rather than one query per host: a fleet of four hundred machines is
 * four requests, and Prometheus does the matching it is good at. The
 * targets are matched on one label — `instance` by default, because
 * relabelling it to `host` or `node` is common enough that guessing would be
 * wrong on a third of installations.
 *
 * **Raw names and units, as the convention requires.** `cpu_percent` and
 * `memory_used_bytes` go across as those names; `MetricKind` and `MetricUnit`
 * decide what this platform calls them. An adapter that translated first
 * would be an adapter whose author had to learn a second vocabulary.
 *
 * **A partial answer is a normal answer.** A target Prometheus returned
 * nothing for comes back in `unknownTargets`, because nine of ten is not
 * success and must not look like it — and because a machine that dropped out
 * of monitoring is exactly the thing an operator needs to see.
 *
 * **`health()` returns no configuration.** Not the address, not the token,
 * not a prefix of either. It is rendered on a screen and written to a log.
 *
 * It has never talked to a real Prometheus. Every request shape and every
 * parse here is tested against faked HTTP, which proves the code and not the
 * integration.
 */
final readonly class PrometheusProvider implements MonitoringProvider
{
    /**
     * The four series worth reading, and what this adapter calls them.
     *
     * PromQL rather than metric names, because what a host reports depends on
     * which exporter is installed and these are the node-exporter expressions
     * every installation of it answers. An installation whose expressions
     * differ writes recording rules, which is what recording rules are for.
     *
     * @var array<string, array{query: string, unit: string}>
     */
    private const array SERIES = [
        'cpu_utilisation' => [
            'query' => '1 - avg by (%1$s) (rate(node_cpu_seconds_total{mode="idle"}[5m]))',
            'unit' => 'ratio',
        ],
        'memory_used_bytes' => [
            'query' => 'node_memory_MemTotal_bytes - node_memory_MemAvailable_bytes',
            'unit' => 'bytes',
        ],
        'disk_used_bytes' => [
            'query' => 'sum by (%1$s) (node_filesystem_size_bytes{fstype!~"tmpfs|overlay"} '
                .'- node_filesystem_avail_bytes{fstype!~"tmpfs|overlay"})',
            'unit' => 'bytes',
        ],
        'uptime_seconds' => [
            'query' => 'time() - node_boot_time_seconds',
            'unit' => 'seconds',
        ],
    ];

    /**
     * @param  Closure(): ?string  $token
     */
    public function __construct(
        private string $baseUrl,
        private Closure $token,
        private string $instanceLabel = 'instance',
        private int $staleAfter = 300,
        private int $timeout = 10,
    ) {}

    public function key(): string
    {
        return 'prometheus';
    }

    public function name(): string
    {
        return 'Prometheus';
    }

    public function vendor(): string
    {
        return 'Prometheus';
    }

    public function capabilities(): CapabilitySet
    {
        // Reads and nothing else. Prometheus has no write this platform wants:
        // silencing an alert belongs to Alertmanager, which is Phase D.
        return CapabilitySet::of([Capability::MetricsRead]);
    }

    public function limits(): RateLimits
    {
        /*
         * One instant query answers for every target at once, so the batch is
         * the whole list. Prometheus is happier with one regex-matched query
         * than with four hundred small ones, and core chunking this into
         * fifties would be core making it slower.
         */
        return new RateLimits(batchSize: 0);
    }

    public function health(): AdapterHealth
    {
        try {
            $response = $this->request()->get('/api/v1/status/buildinfo');
        } catch (Throwable) {
            return AdapterHealth::failing('Prometheus could not be reached.');
        }

        if ($response->status() === 401 || $response->status() === 403) {
            // The credential, named as the thing to look at — without saying
            // anything about what it is.
            return AdapterHealth::failing('Prometheus refused the credential.');
        }

        if (! $response->successful()) {
            return AdapterHealth::failing('Prometheus answered '.$response->status().'.');
        }

        $version = $response->json('data.version');

        return new AdapterHealth(
            HealthState::Ok,
            'Prometheus answered.',
            remoteVersion: is_string($version) ? $version : null,
            checkedAt: CarbonImmutable::now(),
        );
    }

    /**
     * @param  list<string>  $targets
     */
    public function collect(array $targets): SampleBatch
    {
        if ($targets === []) {
            return new SampleBatch(samples: [], unknownTargets: [], collectedAt: CarbonImmutable::now());
        }

        $samples = [];
        $answered = [];

        foreach (self::SERIES as $name => $series) {
            try {
                $response = $this->request()->get('/api/v1/query', [
                    'query' => $this->queryFor($series['query'], $targets),
                ]);
            } catch (Throwable) {
                return SampleBatch::empty('Prometheus could not be reached.');
            }

            if (! $response->successful()) {
                return SampleBatch::empty('Prometheus answered '.$response->status().'.');
            }

            foreach ($this->results($response->json()) as $result) {
                $sample = $this->sample($name, $series['unit'], $result);

                if (! $sample instanceof RawSample) {
                    continue;
                }

                $samples[] = $sample;
                $answered[$sample->target] = true;
            }
        }

        return new SampleBatch(
            samples: $samples,
            unknownTargets: array_values(array_filter(
                $targets,
                static fn (string $target): bool => ! isset($answered[$target]),
            )),
            collectedAt: CarbonImmutable::now(),
        );
    }

    /**
     * The expression, narrowed to the targets asked for.
     *
     * A regex alternation rather than one query per host, and every target is
     * quoted through `preg_quote`: a node key is a hostname somebody typed,
     * and one containing a `.` would otherwise match any character — which is
     * how a query for `db1.example.com` quietly returns `db1xexample.com`.
     *
     * @param  list<string>  $targets
     */
    private function queryFor(string $template, array $targets): string
    {
        $expression = sprintf($template, $this->instanceLabel);

        $alternation = implode('|', array_map(
            static fn (string $target): string => preg_quote($target, '/'),
            $targets,
        ));

        return sprintf(
            '(%s) and on (%s) (up{%s=~"%s"})',
            $expression,
            $this->instanceLabel,
            $this->instanceLabel,
            $alternation,
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function results(mixed $body): array
    {
        if (! is_array($body) || ($body['status'] ?? null) !== 'success') {
            return [];
        }

        $result = $body['data']['result'] ?? null;

        if (! is_array($result)) {
            return [];
        }

        return array_values(array_filter($result, 'is_array'));
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function sample(string $name, string $unit, array $result): ?RawSample
    {
        $metric = is_array($result['metric'] ?? null) ? $result['metric'] : [];
        $target = $metric[$this->instanceLabel] ?? null;
        $value = is_array($result['value'] ?? null) ? $result['value'] : null;

        if (! is_string($target) || $target === '' || $value === null || count($value) < 2) {
            return null;
        }

        // Prometheus answers `[timestamp, "value"]`, the value as a string so
        // that NaN and Inf survive JSON. Anything that is not a number is a
        // reading this adapter does not report rather than a zero it invents.
        if (! is_numeric($value[1])) {
            return null;
        }

        return new RawSample(
            target: $target,
            name: $name,
            value: (float) $value[1],
            unit: $unit,
            sampledAt: is_numeric($value[0])
                ? CarbonImmutable::createFromTimestamp((int) $value[0])
                : null,
            staleAfterSeconds: $this->staleAfter,
        );
    }

    private function request(): PendingRequest
    {
        $request = Http::baseUrl($this->baseUrl)
            ->timeout($this->timeout)
            ->acceptJson();

        $token = ($this->token)();

        return is_string($token) && $token !== ''
            ? $request->withToken($token)
            : $request;
    }
}
