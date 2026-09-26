<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Application\Infrastructure\CapacityOutlooks;
use App\Application\Infrastructure\ListTelemetry;
use App\Http\Concerns\PresentsResources;
use App\Http\Controllers\Controller;
use App\Infrastructure\Resources\Models\ResourceMetric;
use App\Infrastructure\Resources\Models\ResourceNode;
use App\Support\Errors\ForbiddenException;
use App\Support\Identity\CurrentActor;
use App\Support\Organizations\OrganizationContext;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * What is arriving, from where, and what has stopped.
 *
 * The screen nobody asks for until the first time a graph is empty and nobody can
 * say why. In a platform whose whole claim is correlation, "the correlation has no
 * data" is the failure that matters most and the one that is otherwise completely
 * silent — a dashboard with no numbers on it looks like a quiet night.
 *
 * So it shows the absences as well as the readings: how many resources nothing
 * reports on, and which readings have outlived the freshness their own source
 * declared.
 *
 * And what is running out. That answer belongs here rather than on a screen
 * of its own: it is drawn from the same readings, and an operator who has
 * come to ask why a graph is empty is the same operator who needs to know
 * that a disk fills in nine days.
 */
final class TelemetryController extends Controller
{
    use PresentsResources;

    public function __construct(
        private readonly CurrentActor $actor,
        private readonly ListTelemetry $telemetry,
        private readonly CapacityOutlooks $capacity,
        private readonly OrganizationContext $organizations,
    ) {}

    public function index(Request $request): Response
    {
        $this->authorizeFor('infrastructure.telemetry.view');

        $source = $request->string('source')->toString();
        $metric = $request->string('metric')->toString();

        $metrics = $this->telemetry->handle($source, $metric);

        return Inertia::render('Admin/Resources/Telemetry', [
            'metrics' => [
                'data' => array_map(
                    fn (ResourceMetric $row): array => [
                        ...$this->metricRow($row),
                        'node' => $row->node instanceof ResourceNode
                            ? $this->nodeRow($row->node)
                            : null,
                    ],
                    $metrics->items(),
                ),
                'currentPage' => $metrics->currentPage(),
                'lastPage' => $metrics->lastPage(),
                'total' => $metrics->total(),
                'links' => $metrics->linkCollection()->all(),
            ],
            'filters' => [
                'source' => $source === '' ? null : $source,
                'metric' => $metric === '' ? null : $metric,
            ],
            'sources' => $this->telemetry->sources(),
            'stats' => $this->telemetry->counts(),
            'unwatched' => array_map(
                $this->nodeRow(...),
                $this->telemetry->unwatched(),
            ),
            'capacity' => $this->capacity(),
        ]);
    }

    /**
     * What is filling, soonest first.
     *
     * Only resources that are actually heading somewhere: a list that said
     * "not filling" forty times would be a list nobody reads to the bottom.
     * Each row carries the number of days the line was drawn through, because
     * a date from eight points and a date from ninety deserve different
     * amounts of belief.
     *
     * @return list<array<string, mixed>>
     */
    private function capacity(): array
    {
        $organizationId = $this->organizations->id();

        if (! is_string($organizationId)) {
            return [];
        }

        return array_map(
            static fn (array $row): array => [
                'id' => $row['node']->id.':'.$row['metric']->value,
                'node' => $row['node']->label,
                'nodeKey' => $row['node']->node_key,
                'metric' => $row['metric']->value,
                'metricLabel' => (string) __('infrastructure.metrics.'.$row['metric']->value),
                'utilisation' => round($row['outlook']->utilisation(), 4),
                'daysRemaining' => $row['outlook']->daysRemaining(),
                'fullOn' => $row['outlook']->fullOn?->toDateString(),
                'days' => $row['outlook']->days,
            ],
            $this->capacity->soonest($organizationId),
        );
    }

    private function authorizeFor(string $permission): void
    {
        if (! $this->actor->can($permission)) {
            throw new ForbiddenException(__('infrastructure.errors.not_permitted'));
        }
    }
}
