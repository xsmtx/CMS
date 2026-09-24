<?php

declare(strict_types=1);

namespace App\Application\Infrastructure;

use App\Domain\Infrastructure\MetricKind;
use App\Domain\Infrastructure\MetricSample;
use App\Domain\Infrastructure\MetricUnit;
use App\Domain\Infrastructure\RawSample;
use Carbon\CarbonImmutable;

/**
 * Turns what a source said into what this platform stores.
 *
 * The whole value of a telemetry layer is here: `node_cpu_utilisation` from
 * Prometheus, `system.cpu.util` from Zabbix and `cpu_percent` from a vendor API
 * are one question, and an operator comparing a Proxmox host with a cPanel server
 * must not have to know which of the three they are looking at. Twenty-three
 * adapters each deciding what "cpu" means is twenty-three screens that cannot be
 * compared.
 *
 * It **refuses** rather than improvises, in three ways:
 *
 * - a name no `MetricKind` claims is counted and dropped, because a table that
 *   accepts any metric name is a time-series database nobody sized and §14 is
 *   explicit that the series stays outside;
 * - a unit from the wrong dimension — bytes offered for a CPU ratio — is counted
 *   and dropped, because converting it is impossible and storing it is a mistake
 *   nobody can see afterwards;
 * - a unit that is simply absent is taken to be the metric's own canonical unit,
 *   which is the one guess that is safe: it is what a source that never mentions
 *   units is almost always already using, and being wrong about it is visible on
 *   the screen rather than silent.
 */
final readonly class SampleNormalizer
{
    /**
     * @param  list<RawSample>  $raw
     */
    public function normalize(array $raw, ?CarbonImmutable $now = null): NormalizedBatch
    {
        $at = $now ?? CarbonImmutable::now();

        $samples = [];
        $unmapped = [];
        $mismatched = [];

        foreach ($raw as $sample) {
            $kind = MetricKind::match($sample->name);

            if (! $kind instanceof MetricKind) {
                $unmapped[$sample->name] = ($unmapped[$sample->name] ?? 0) + 1;

                continue;
            }

            $unit = $this->unitOf($sample, $kind);

            if (! $unit instanceof MetricUnit) {
                $mismatched[$sample->name] = ($mismatched[$sample->name] ?? 0) + 1;

                continue;
            }

            $samples[] = new MetricSample(
                target: $sample->target,
                metric: $kind,
                value: $unit->toCanonical($sample->value),
                sampledAt: $sample->sampledAt ?? $at,
                staleAfterSeconds: $sample->staleAfterSeconds,
            );
        }

        return new NormalizedBatch($samples, $unmapped, $mismatched);
    }

    /**
     * The unit to read this sample in, or null when the source's unit belongs to
     * another dimension entirely.
     */
    private function unitOf(RawSample $sample, MetricKind $kind): ?MetricUnit
    {
        if ($sample->unit === null || trim($sample->unit) === '') {
            /*
             * The source may have written the unit into the metric's name —
             * `cpu_percent`, `memory_used_mb`. Reading that is the opposite of
             * guessing, and not reading it stores forty as a ratio of forty,
             * which is what the worked example found the first time it ran.
             *
             * It is still checked against the metric's dimension below, so
             * `cpu_bytes` is a mismatch rather than a conversion.
             */
            $named = MetricKind::unitFromName($sample->name);

            if ($named instanceof MetricUnit) {
                return $named->canonical() === $kind->unit() ? $named : null;
            }

            return $kind->unit();
        }

        $declared = MetricUnit::tryFrom(strtolower(trim($sample->unit)));

        if (! $declared instanceof MetricUnit) {
            // An unrecognised unit string is a mismatch rather than a guess.
            // Guessing between bytes and megabytes is a factor of a million and
            // the screen would look plausible either way.
            return null;
        }

        return $declared->canonical() === $kind->unit() ? $declared : null;
    }
}
