<?php

declare(strict_types=1);

namespace App\Application\Infrastructure;

use App\Domain\Infrastructure\MetricKind;
use Illuminate\Support\Str;

/**
 * What a metric is called, for somebody reading a screen.
 *
 * `PermissionNames` and `CapabilityNames` with a third array, and it exists
 * because the same trap caught the same way a third time. A `MetricKind`'s
 * value has a dot in it, so `__('infrastructure.metrics.disk.total')` asks the
 * translator to walk three levels of nesting, finds nothing, and returns the
 * key — which reads as "untranslated" while looking like it works.
 *
 * It looked like it worked for a very long time. Every metric name on the
 * Telemetry screen, in the Explorer's drawer, on the capacity panel and in an
 * alert's own label was its own translation key, in both languages, since
 * Phase A. Nothing caught it: the wording was present and correct in `lang/`,
 * the tests compared one broken call against another, and the only screen
 * that would have shown it needed a node with readings on it.
 *
 * The derived fallback matters here for the reason it does for a capability:
 * a module may report a kind core has no wording for yet, and `disk.iops`
 * reading as "Disk iops" is legible where the key is not.
 */
final class MetricNames
{
    /** @var array<string, mixed>|null */
    private ?array $wording = null;

    public function label(MetricKind|string $metric): string
    {
        $value = $metric instanceof MetricKind ? $metric->value : $metric;
        $label = $this->wording()[$value] ?? null;

        if (is_string($label) && $label !== '') {
            return $label;
        }

        return Str::ucfirst(str_replace(['.', '_'], ' ', $value));
    }

    /**
     * Whether somebody has actually worded this one.
     *
     * The guard in `VocabularyTest` asks this rather than comparing the label
     * against the key, because the derived fallback is legible enough that a
     * comparison would pass for every metric nobody has named.
     */
    public function isWorded(MetricKind|string $metric): bool
    {
        $value = $metric instanceof MetricKind ? $metric->value : $metric;
        $label = $this->wording()[$value] ?? null;

        return is_string($label) && $label !== '';
    }

    /**
     * @return array<string, mixed>
     */
    private function wording(): array
    {
        if ($this->wording !== null) {
            return $this->wording;
        }

        $wording = trans('infrastructure.metrics');

        return $this->wording = is_array($wording) ? $wording : [];
    }
}
