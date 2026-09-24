<?php

declare(strict_types=1);

namespace Example\FileProbe;

use App\Domain\Health\HealthState;
use App\Domain\Infrastructure\AdapterHealth;
use App\Domain\Infrastructure\Capability;
use App\Domain\Infrastructure\CapabilitySet;
use App\Domain\Infrastructure\Contracts\MonitoringProvider;
use App\Domain\Infrastructure\RateLimits;
use App\Domain\Infrastructure\RawSample;
use App\Domain\Infrastructure\SampleBatch;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * Measurements from a file something else writes.
 *
 * A `MonitoringProvider` and nothing else: it declares one capability,
 * `MetricsRead`, so core never offers a button this cannot honour and the
 * Adapters screen can say truthfully that this adapter changes nothing.
 *
 * Three things here are the convention rather than this adapter's particulars,
 * and a future FortiGate or Prometheus adapter should copy them.
 *
 * **It reports raw names and units and lets core do the naming.** `cpu_percent`
 * goes across as `cpu_percent`, and `MetricKind`/`MetricUnit` decide that it is
 * `cpu.utilisation` as a ratio. An adapter that translated first would be an
 * adapter whose author had to learn a second vocabulary, and twenty-three of them
 * would disagree.
 *
 * **A partial answer is a normal answer.** Targets the file says nothing about come
 * back in `unknownTargets` rather than being quietly dropped: nine of ten is not
 * success and must not look like it.
 *
 * **`health()` returns no configuration.** Not the path — not even when the
 * problem *is* the path. "The measurements file could not be read" is what an
 * operator needs; the path is on the settings form they configured, and a health
 * message is rendered on a screen and written to a log.
 */
final readonly class FileProbe implements MonitoringProvider
{
    public function __construct(
        private string $path,
        private int $staleAfter = 600,
    ) {}

    public function key(): string
    {
        return 'file-probe';
    }

    public function name(): string
    {
        return 'File Probe';
    }

    public function vendor(): string
    {
        return 'InfraCMS example';
    }

    public function capabilities(): CapabilitySet
    {
        return CapabilitySet::of([Capability::MetricsRead]);
    }

    public function limits(): RateLimits
    {
        // One file read answers for everything in it, so the batch is the whole
        // list. Declared rather than assumed, because core chunks by this number
        // and a poller that wanted one host per call would say 1.
        return new RateLimits(batchSize: 0);
    }

    public function health(): AdapterHealth
    {
        $document = $this->read();

        if ($document === null) {
            // No path in the message, deliberately. See the class docblock.
            return AdapterHealth::failing('The measurements file could not be read or is not JSON.');
        }

        $sampledAt = $this->sampledAt($document);
        $resources = is_array($document['resources'] ?? null) ? $document['resources'] : [];

        if ($resources === []) {
            return AdapterHealth::degraded('The measurements file has no resources in it.');
        }

        if ($this->staleAfter > 0 && $sampledAt !== null
            && CarbonImmutable::now()->timestamp - $sampledAt->timestamp > $this->staleAfter) {
            return AdapterHealth::degraded('The measurements file has not been written recently.');
        }

        return new AdapterHealth(
            HealthState::Ok,
            sprintf('%d resources in the file.', count($resources)),
            checkedAt: CarbonImmutable::now(),
        );
    }

    /**
     * @param  list<string>  $targets
     */
    public function collect(array $targets): SampleBatch
    {
        $document = $this->read();

        if ($document === null) {
            return SampleBatch::empty('The measurements file could not be read.');
        }

        $resources = is_array($document['resources'] ?? null) ? $document['resources'] : [];
        $sampledAt = $this->sampledAt($document);

        $samples = [];
        $unknown = [];

        foreach ($targets as $target) {
            $readings = $resources[$target] ?? null;

            if (! is_array($readings)) {
                $unknown[] = $target;

                continue;
            }

            foreach ($readings as $name => $reading) {
                if (! is_string($name)) {
                    continue;
                }

                $sample = $this->sample($target, $name, $reading, $sampledAt);

                if ($sample instanceof RawSample) {
                    $samples[] = $sample;
                }
            }
        }

        return new SampleBatch(
            samples: $samples,
            unknownTargets: $unknown,
            collectedAt: CarbonImmutable::now(),
        );
    }

    /**
     * One reading, in whichever of the two shapes the file used.
     */
    private function sample(
        string $target,
        string $name,
        mixed $reading,
        ?CarbonImmutable $sampledAt,
    ): ?RawSample {
        if (is_int($reading) || is_float($reading)) {
            return new RawSample(
                target: $target,
                name: $name,
                value: (float) $reading,
                sampledAt: $sampledAt,
                staleAfterSeconds: $this->staleAfter > 0 ? $this->staleAfter : null,
            );
        }

        if (! is_array($reading) || ! isset($reading['value'])) {
            return null;
        }

        $value = $reading['value'];

        if (! is_int($value) && ! is_float($value)) {
            return null;
        }

        return new RawSample(
            target: $target,
            name: $name,
            value: (float) $value,
            unit: is_string($reading['unit'] ?? null) ? $reading['unit'] : null,
            sampledAt: $sampledAt,
            staleAfterSeconds: $this->staleAfter > 0 ? $this->staleAfter : null,
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    private function read(): ?array
    {
        try {
            if (! is_file($this->path) || ! is_readable($this->path)) {
                return null;
            }

            $contents = file_get_contents($this->path);

            if ($contents === false) {
                return null;
            }

            $decoded = json_decode($contents, true, 32, JSON_THROW_ON_ERROR);

            return is_array($decoded) ? $decoded : null;
        } catch (Throwable) {
            // Anything at all: a half-written file, a permission change, a
            // filesystem that vanished. Core turns a thrown adapter into
            // `failing` anyway; returning null keeps the two paths identical.
            return null;
        }
    }

    /**
     * @param  array<string, mixed>  $document
     */
    private function sampledAt(array $document): ?CarbonImmutable
    {
        $value = $document['sampled_at'] ?? null;

        if (! is_string($value)) {
            return null;
        }

        try {
            return CarbonImmutable::parse($value);
        } catch (Throwable) {
            return null;
        }
    }
}
