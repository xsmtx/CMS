<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Application\Infrastructure\CapacityOutlooks;
use App\Application\Infrastructure\ImpactSummary;
use App\Application\Infrastructure\ListResources;
use App\Application\Infrastructure\OwnershipHistory;
use App\Application\Infrastructure\ResourceTree;
use App\Domain\Infrastructure\ResourceKind;
use App\Http\Concerns\PresentsResources;
use App\Http\Controllers\Controller;
use App\Infrastructure\Provisioning\Models\Server;
use App\Infrastructure\Resources\Models\ResourceMetric;
use App\Infrastructure\Resources\Models\ResourceNode;
use App\Support\Errors\ForbiddenException;
use App\Support\Identity\CurrentActor;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The Explorer: everything in the graph, and what hangs off what.
 *
 * The list is the screen and the detail is a drawer, which means the detail is an
 * `Inertia::optional` prop on this same route rather than an endpoint of its own
 * (the Phase 11 rule). Nothing below is built on an ordinary page load; asking for
 * `peek` by name builds one resource's context instead of re-running the list.
 *
 * Live nodes by default. A retired node is kept, because a terminated service is
 * what an incident review needs to see — and showing them by default would make
 * the screen a graveyard within a year.
 */
final class ResourceController extends Controller
{
    use PresentsResources;

    public function __construct(
        private readonly CurrentActor $actor,
        private readonly ListResources $resources,
        private readonly ResourceTree $tree,
        private readonly ImpactSummary $impact,
        private readonly OwnershipHistory $history,
        private readonly CapacityOutlooks $capacity,
    ) {}

    public function index(Request $request): Response
    {
        $this->authorizeFor('infrastructure.resources.view');

        $kind = $request->string('kind')->toString();
        $health = $request->string('health')->toString();
        $search = trim($request->string('q')->toString());
        $retired = $request->boolean('retired');

        $nodes = $this->resources->handle($kind, $health, $search, $retired);

        return Inertia::render('Admin/Resources/Explorer', [
            'nodes' => [
                'data' => array_map(
                    $this->nodeRow(...),
                    $nodes->items(),
                ),
                'currentPage' => $nodes->currentPage(),
                'lastPage' => $nodes->lastPage(),
                'total' => $nodes->total(),
                'links' => $nodes->linkCollection()->all(),
            ],
            'filters' => [
                'kind' => $kind === '' ? null : $kind,
                'health' => $health === '' ? null : $health,
                'q' => $search === '' ? null : $search,
                'retired' => $retired,
            ],
            'kinds' => $this->kindOptions(),
            'healthStates' => $this->healthOptions(),
            'stats' => $this->resources->counts(),
            // Built only when asked for by name. An ordinary page load renders
            // the list and nothing else.
            'peek' => Inertia::optional(fn (): ?array => $this->peek($request)),
        ]);
    }

    /**
     * One resource in context: what it sits on, what it holds, who it affects.
     *
     * @return array<string, mixed>|null
     */
    private function peek(Request $request): ?array
    {
        $id = $request->string('node')->toString();

        if ($id === '') {
            return null;
        }

        $node = ResourceNode::query()->whereKey($id)->first();

        if (! $node instanceof ResourceNode) {
            // A drawer that asked for something outside the boundary has
            // nothing to show, which is the same answer for "gone" and "not
            // yours" — and the same answer is the point.
            return null;
        }

        $metrics = ResourceMetric::query()
            ->where('resource_node_id', $node->id)
            ->orderBy('metric')
            ->get();

        return [
            'node' => $this->nodeRow($node),
            'above' => array_map(
                $this->treeRow(...),
                array_slice($this->tree->above($node), 1),
            ),
            'below' => array_map(
                $this->treeRow(...),
                array_slice($this->tree->below($node, 4), 1),
            ),
            'impact' => $this->impact->for($node)->toArray(app()->getLocale()),
            'history' => array_map(
                $this->historyRow(...),
                $this->history->for($node, limit: 20),
            ),
            'metrics' => array_values($metrics
                ->map(fn (ResourceMetric $metric): array => $this->metricRow($metric))
                ->all()),
            'capacity' => $this->capacityFor($node),
            'operatorState' => $this->operatorState($node),
        ];
    }

    /**
     * Where this one resource is heading, flat lines included.
     *
     * §3 asks for capacity beside the readings on a resource's own view, and the
     * two answers are different questions: the Telemetry screen lists what is
     * filling across the estate, and this says what is known about the thing
     * somebody has actually opened. "It has been flat for three months" is worth
     * printing here and noise there.
     *
     * @return list<array<string, mixed>>
     */
    private function capacityFor(ResourceNode $node): array
    {
        return array_map(
            static fn (array $row): array => [
                'metric' => $row['metric']->value,
                'metricLabel' => (string) __('infrastructure.metrics.'.$row['metric']->value),
                'utilisation' => round($row['outlook']->utilisation(), 4),
                'filling' => $row['outlook']->isFilling(),
                'daysRemaining' => $row['outlook']->daysRemaining(),
                'fullOn' => $row['outlook']->fullOn?->toDateString(),
                'days' => $row['outlook']->days,
            ],
            $this->capacity->forNode($node->id),
        );
    }

    /**
     * What an operator has said about the thing itself, as opposed to what was
     * discovered about it.
     *
     * A server in maintenance is the case this exists for: the graph would call
     * that node's health `unknown` - nothing is checking a box that was taken out
     * of service on purpose - and a screen that only showed the discovered fact
     * would read as a monitoring gap rather than as somebody's decision. Only
     * servers have such a state today; the rest answer null rather than inventing
     * one.
     *
     * @return array{state: string, stateLabel: string}|null
     */
    private function operatorState(ResourceNode $node): ?array
    {
        if ($node->kind !== ResourceKind::Server || $node->subject_id === null) {
            return null;
        }

        $server = Server::query()->whereKey($node->subject_id)->first();

        if (! $server instanceof Server) {
            return null;
        }

        return [
            'state' => $server->status->value,
            'stateLabel' => (string) __($server->status->labelKey()),
        ];
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    private function kindOptions(): array
    {
        $kinds = array_values(array_unique([...ResourceKind::Core, ...$this->resources->kinds()]));

        return array_map(
            fn (string $kind): array => ['value' => $kind, 'label' => $this->kindLabel($kind)],
            $kinds,
        );
    }

    private function authorizeFor(string $permission): void
    {
        if (! $this->actor->can($permission)) {
            throw new ForbiddenException(__('infrastructure.errors.not_permitted'));
        }
    }
}
