<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Staff;

use App\Application\Infrastructure\ImpactSummary;
use App\Domain\Infrastructure\ResourceKind;
use App\Http\Controllers\Controller;
use App\Infrastructure\Dcim\Models\Rack;
use App\Infrastructure\Resources\Models\ResourceMetric;
use App\Infrastructure\Resources\Models\ResourceNode;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * "What is this, and what is on it?" (§26).
 *
 * The two reads a technician standing in a datacenter actually makes: what a
 * scanned asset tag resolves to, and what the machine in front of them is
 * carrying. Everything here is read-only and there is deliberately no write
 * of any kind — a phone that could reconfigure a machine in a rack is the
 * thing §26 says stays on the web.
 *
 * **The search is exact, never fuzzy.** A technician is holding the label, so
 * a near-match would be the wrong machine confidently identified — and the
 * consequence of that is somebody pulling a disk out of a customer's server.
 * The `RecordSamples::byHostname()` rule, applied where it matters most.
 */
final class LookupController extends Controller
{
    public function __construct(private readonly ImpactSummary $impact) {}

    /**
     * What a node key or an asset tag is.
     */
    public function show(Request $request): JsonResponse
    {
        $key = trim((string) $request->query('key', ''));

        if ($key === '') {
            return response()->json(['data' => null]);
        }

        $node = ResourceNode::query()
            ->whereNull('retired_at')
            ->where('node_key', $key)
            ->first();

        if (! $node instanceof ResourceNode) {
            // A rack is not in the graph — it is a row somebody typed — so
            // the same scan has to be able to resolve to one.
            $rack = Rack::query()->where('name', $key)->first();

            return response()->json([
                'data' => $rack === null ? null : [
                    'kind' => 'rack',
                    'key' => $rack->name,
                    'label' => $rack->name,
                    'units' => $rack->units,
                    // Free rather than occupied: the question somebody at a
                    // rack is asking is whether the thing in their hands
                    // will fit.
                    'free' => $rack->freeUnits(),
                ],
            ]);
        }

        $figures = $this->impact->for($node);

        return response()->json([
            'data' => [
                'kind' => $node->kind,
                'key' => $node->node_key,
                'label' => $node->label,
                'health' => $node->health,
                // What the adapter reported about it: the model, the serial,
                // the firmware. Declared in the payload and drawn nowhere
                // for a phase, which is how a fact goes missing.
                'attributes' => $node->attributes ?? [],
                // The one thing only this platform can answer: how many
                // customers and services are underneath.
                'impact' => [
                    'customers' => $figures->customers,
                    'services' => $figures->services,
                ],
                'readings' => ResourceMetric::query()
                    ->where('resource_node_id', $node->id)
                    ->get()
                    ->map(fn (ResourceMetric $metric): array => [
                        'metric' => $metric->metric?->value,
                        'unit' => $metric->unit?->value,
                        'value' => $metric->value,
                        'sampledAt' => $metric->sampled_at->toIso8601String(),
                        // A stale reading is marked rather than hidden: a
                        // machine that stopped reporting is a monitoring
                        // problem, and pretending the last value is current
                        // is how somebody acts on a number from Tuesday.
                        'stale' => $metric->isStale(),
                    ])
                    ->values(),
            ],
        ]);
    }

    /**
     * The servers this installation has, for the list a technician scrolls.
     */
    public function servers(): JsonResponse
    {
        $nodes = ResourceNode::query()
            ->where('kind', ResourceKind::Server)
            ->whereNull('retired_at')
            ->orderBy('label')
            ->limit(500)
            ->get();

        return response()->json([
            'data' => $nodes->map(fn (ResourceNode $node): array => [
                'key' => $node->node_key,
                'label' => $node->label,
                'health' => $node->health,
                'lastSeenAt' => $node->last_seen_at?->toIso8601String(),
            ])->values(),
        ]);
    }
}
