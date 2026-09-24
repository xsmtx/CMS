<?php

declare(strict_types=1);

namespace App\Http\Concerns;

use App\Application\Infrastructure\HistoryRow;
use App\Application\Infrastructure\TreeRow;
use App\Domain\Health\HealthState;
use App\Domain\Infrastructure\MetricKind;
use App\Infrastructure\Resources\Models\ResourceMetric;
use App\Infrastructure\Resources\Models\ResourceNode;
use Carbon\CarbonImmutable;

/**
 * Turning graph rows into the shapes the three Infrastructure screens render.
 *
 * Shared rather than repeated, because all three screens print a node and two of
 * them print a measurement, and two vocabularies for one thing is how a product
 * ends up calling one state two names.
 *
 * Two rules it enforces on the way out.
 *
 * **A label that has no translation reads as itself.** A module's kind —
 * `switch_port` — has no entry in `lang/`, and turning it into "Switch port" is
 * legible where the raw translation key would not be. The same fallback the
 * notification placeholders use, for the same reason: a mistake should be
 * visible, not disguised.
 *
 * **Nothing here reaches for a subject's columns.** A node carries a cached label
 * and that is what the list prints; the drawer offers a link to the real record.
 * A presenter that loaded every node's service to print a customer name would be
 * the lazy-load violation `LazyLoadingTest` exists to catch, on a screen that can
 * show twenty-five of them.
 */
trait PresentsResources
{
    /**
     * @return array<string, mixed>
     */
    protected function nodeRow(ResourceNode $node): array
    {
        return [
            'id' => $node->id,
            'kind' => $node->kind,
            'kindLabel' => $this->kindLabel($node->kind),
            'key' => $node->node_key,
            'label' => $node->label,
            'source' => $node->source,
            'health' => $node->health,
            'healthLabel' => $this->healthLabel($node->health),
            'healthMessage' => $node->health_message,
            'attributes' => $node->attributes ?? [],
            'lastSeenAt' => $node->last_seen_at?->toIso8601String(),
            'retiredAt' => $node->retired_at?->toIso8601String(),
            'subject' => $this->subjectLink($node),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function treeRow(TreeRow $row): array
    {
        return [
            'node' => $this->nodeRow($row->node),
            'depth' => $row->depth,
            'relation' => $row->relation?->value,
            'relationLabel' => $row->relation === null
                ? null
                : (string) __($row->relation->labelKey()),
            'parentId' => $row->parentId,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function historyRow(HistoryRow $row): array
    {
        return [
            'other' => $this->nodeRow($row->other),
            'relation' => $row->relation->value,
            'relationLabel' => (string) __($row->relation->labelKey()),
            'nodeIsContainer' => $row->nodeIsContainer,
            'observedAt' => $row->observedAt->toIso8601String(),
            'endedAt' => $row->endedAt?->toIso8601String(),
            'open' => $row->isOpen(),
            'days' => $row->days(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function metricRow(ResourceMetric $metric): array
    {
        $kind = $metric->metric;

        return [
            'id' => $metric->id,
            'nodeId' => $metric->resource_node_id,
            'metric' => $kind->value ?? '',
            // A metric this version does not recognise is shown as what the row
            // says rather than hidden: a module written against a newer core is a
            // thing an operator should be able to see, not a blank line.
            'metricLabel' => $kind instanceof MetricKind
                ? (string) __('infrastructure.metrics.'.$kind->value)
                : ($metric->getRawOriginal('metric') ?? ''),
            'unit' => $metric->unit?->value,
            'unitLabel' => $metric->unit === null ? '' : (string) __($metric->unit->labelKey()),
            'value' => $metric->value,
            'higherIsWorse' => $kind?->higherIsWorse(),
            'sampledAt' => $metric->sampled_at->toIso8601String(),
            'staleAfterSeconds' => $metric->stale_after_seconds,
            'stale' => $metric->isStale(CarbonImmutable::now()),
            'source' => $metric->source,
        ];
    }

    protected function kindLabel(string $kind): string
    {
        $key = 'infrastructure.kinds.'.$kind;
        $label = __($key);

        return is_string($label) && $label !== $key
            ? $label
            : ucfirst(str_replace('_', ' ', $kind));
    }

    protected function healthLabel(string $health): string
    {
        $state = HealthState::tryFrom($health);

        return $state instanceof HealthState
            ? (string) __($state->labelKey())
            : (string) __('health.states.unknown');
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    protected function healthOptions(): array
    {
        $options = array_map(
            static fn (HealthState $state): array => [
                'value' => $state->value,
                'label' => (string) __($state->labelKey()),
            ],
            HealthState::cases(),
        );

        return [
            ...$options,
            [
                'value' => ResourceNode::HealthUnknown,
                'label' => (string) __('health.states.unknown'),
            ],
        ];
    }

    /**
     * Where the real record lives, when there is one.
     *
     * A link rather than the record: the graph gets you to a thing and the thing's
     * own screen tells you about it (ADR 0043). Only the kinds core projects have
     * a route, and a module's node has none until the module gives it one.
     *
     * @return array<string, string>|null
     */
    private function subjectLink(ResourceNode $node): ?array
    {
        if ($node->subject_id === null) {
            return null;
        }

        /*
         * Only a service has a screen of its own today. Servers live on the Apps
         * → Infrastructure list and organizations on theirs, so those links go to
         * the list rather than to a record that does not exist — which is worth a
         * comment because the obvious fix is to invent `/admin/servers/{id}` here
         * and get a 404 from a screen that looks correct.
         */
        $href = match ($node->kind) {
            'service' => '/admin/services/'.$node->subject_id,
            'server' => '/admin/apps/infrastructure',
            'organization' => '/admin/organizations',
            default => null,
        };

        return $href === null ? null : ['href' => $href];
    }
}
