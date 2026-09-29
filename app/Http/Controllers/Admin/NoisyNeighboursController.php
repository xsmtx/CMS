<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Application\Infrastructure\MetricNames;
use App\Application\Intelligence\NeighbourReport;
use App\Application\Intelligence\NoisyNeighbours;
use App\Application\Intelligence\NoisyRow;
use App\Http\Controllers\Controller;
use App\Support\Errors\ForbiddenException;
use App\Support\Identity\CurrentActor;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Which service on a machine is using more than its neighbours (§21).
 *
 * It reads `infrastructure.telemetry.view`, because that is exactly what it
 * is: a reading of telemetry this installation already holds, arranged to
 * answer one question. Inventing a permission for a screen that shows what
 * somebody may already see would be a permission that only confuses — the
 * same call the customer health screen made.
 *
 * The multiple is on the query string rather than in a setting. It is a
 * convention rather than a rule this product invented, and a number that
 * decides what is listed belongs where the operator can see it and change it.
 */
final class NoisyNeighboursController extends Controller
{
    /**
     * What the screen offers. Deliberately few: this is a filter, not a dial.
     *
     * @var list<float>
     */
    private const array Multiples = [2.0, 3.0, 5.0, 10.0];

    public function __construct(private readonly MetricNames $names) {}

    public function index(Request $request, CurrentActor $actor, NoisyNeighbours $neighbours): Response
    {
        if (! $actor->can('infrastructure.telemetry.view')) {
            throw new ForbiddenException(__('automation.errors.not_permitted'));
        }

        $multiple = (float) $request->query('multiple', (string) NoisyNeighbours::DefaultMultiple);

        if (! in_array($multiple, self::Multiples, strict: true)) {
            $multiple = NoisyNeighbours::DefaultMultiple;
        }

        $report = $neighbours->on($multiple);

        return Inertia::render('Admin/Intelligence/NoisyNeighbours', [
            'rows' => array_map($this->row(...), $report->rows),
            'measured' => $report->measuredAnything(),
            /*
             * Every sentence with a number in it is worded here.
             * `useTranslations()` has no `trans_choice`, so a count worded in
             * the browser reads as "1 machines" — and these three sentences
             * are the whole of what this screen says when it has nothing to
             * list, which is exactly when the wording matters most.
             */
            'unmeasuredDetail' => $this->unmeasured($report),
            'emptyDetail' => trans_choice('intelligence.noisy.empty_detail', $report->nodesCompared, [
                'count' => $report->nodesCompared,
            ]),
            'method' => (string) __('intelligence.noisy.method', [
                'minimum' => (string) $report->minimumNeighbours,
            ]),
            'multiple' => $multiple,
            'multiples' => array_map(
                static fn (float $option): array => [
                    'value' => (string) $option,
                    'label' => __('intelligence.noisy.multiple_option', ['times' => (string) $option]),
                ],
                self::Multiples,
            ),
        ]);
    }

    /**
     * Why there was nothing to compare, which is not why nothing is wrong.
     */
    private function unmeasured(NeighbourReport $report): string
    {
        if ($report->nodesWithoutPerServiceMetrics + $report->nodesTooFew === 0) {
            return (string) __('intelligence.noisy.unmeasured_none');
        }

        $parts = [];

        if ($report->nodesWithoutPerServiceMetrics > 0) {
            $parts[] = trans_choice(
                'intelligence.noisy.unmeasured_hosts',
                $report->nodesWithoutPerServiceMetrics,
                ['count' => $report->nodesWithoutPerServiceMetrics],
            );
        }

        if ($report->nodesTooFew > 0) {
            $parts[] = trans_choice(
                'intelligence.noisy.unmeasured_few',
                $report->nodesTooFew,
                ['count' => $report->nodesTooFew, 'minimum' => $report->minimumNeighbours],
            );
        }

        return implode(' ', $parts);
    }

    /**
     * @return array<string, mixed>
     */
    private function row(NoisyRow $row): array
    {
        return [
            'nodeKey' => $row->nodeKey,
            'nodeLabel' => $row->nodeLabel,
            'serviceKey' => $row->serviceKey,
            'serviceLabel' => $row->serviceLabel,
            'href' => $row->serviceId === null ? null : '/admin/services/'.$row->serviceId,
            // The metric's own wording, which lives in one reader — a dotted
            // value handed to `__()` walks three levels of nesting and comes
            // back as the key.
            'metric' => $row->metric->value,
            'metricLabel' => $this->names->label($row->metric),
            'unit' => $row->unit->value,
            // Without the word, "412" under a heading that says "This
            // service" is a number nobody can read.
            'unitLabel' => (string) __($row->unit->labelKey()),
            'value' => $row->value,
            'median' => $row->median,
            'times' => $row->times,
            'neighbours' => $row->neighbours,
        ];
    }
}
