<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Application\Infrastructure\MetricNames;
use App\Domain\Infrastructure\MetricKind;
use App\Domain\Intelligence\AllocationStrategy;
use App\Domain\Intelligence\CostPeriod;
use App\Domain\Intelligence\CostScope;
use App\Http\Controllers\Controller;
use App\Infrastructure\Catalog\Models\Product;
use App\Infrastructure\Identity\Models\StaffUser;
use App\Infrastructure\Intelligence\Models\CostEntry;
use App\Infrastructure\Provisioning\Models\Server;
use App\Support\Audit\Facades\Audit;
use App\Support\Errors\ForbiddenException;
use App\Support\Identity\CurrentActor;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * What the provider pays somebody else (§21).
 *
 * **Nothing here is discovered.** No adapter reports a Hetzner invoice or
 * the rent, so this is rows an operator types — the same shape as tax rules
 * and dunning steps, and core ships none of them either.
 *
 * **The strategy is on the row, not in the report.** A figure that cannot
 * say which arithmetic produced it is a figure nobody can argue with, which
 * sounds like a strength and is the opposite.
 */
final class CostController extends Controller
{
    public function index(CurrentActor $actor): Response
    {
        $this->refuseUnless($actor);

        $entries = CostEntry::query()
            // The presenter reads the subject's name, so it is loaded with
            // the page. Strict mode only reports a lazy load above one row,
            // which is how this shape keeps reaching a browser.
            ->with('subject')
            ->orderBy('scope')
            ->orderBy('label')
            ->paginate(50);

        return Inertia::render('Admin/Intelligence/Costs', [
            'entries' => [
                'data' => $this->rows(array_values($entries->items())),
                'links' => $entries->linkCollection()->toArray(),
                'currentPage' => $entries->currentPage(),
                'lastPage' => $entries->lastPage(),
                'total' => $entries->total(),
            ],
            'options' => [
                'scopes' => array_map(
                    static fn (CostScope $scope): array => [
                        'value' => $scope->value,
                        'label' => (string) __($scope->labelKey()),
                        // The form asks for a subject only where there is
                        // one to ask for, and the enum is what says so.
                        'needsSubject' => $scope->needsSubject(),
                    ],
                    CostScope::cases(),
                ),
                'periods' => array_map(
                    static fn (CostPeriod $period): array => [
                        'value' => $period->value,
                        'label' => (string) __($period->labelKey()),
                    ],
                    CostPeriod::cases(),
                ),
                'strategies' => array_map(
                    static fn (AllocationStrategy $strategy): array => [
                        'value' => $strategy->value,
                        'label' => (string) __($strategy->labelKey()),
                        'description' => (string) __($strategy->descriptionKey()),
                        'needsMetric' => $strategy->needsMetric(),
                    ],
                    AllocationStrategy::cases(),
                ),
                'metrics' => $this->metrics(),
                'servers' => Server::query()->orderBy('name')->get()
                    ->map(static fn (Server $server): array => [
                        'value' => $server->id,
                        'label' => $server->name,
                    ])->values()->all(),
                'products' => Product::query()->orderBy('name')->get()
                    ->map(static fn (Product $product): array => [
                        'value' => $product->id,
                        'label' => $product->name,
                    ])->values()->all(),
            ],
            'can' => ['manage' => $actor->can('intelligence.costs.manage')],
        ]);
    }

    public function store(Request $request, CurrentActor $actor): RedirectResponse
    {
        $this->refuseUnless($actor);

        $data = $this->validated($request);
        $staff = $this->staff($actor);

        // Built rather than spread into `create()`: a loose array shape
        // does not satisfy the model's own property list.
        $entry = new CostEntry;
        $entry->fill($this->columns($data));
        $entry->organization_id = $staff->organization_id;
        $entry->save();

        Audit::action('intelligence.cost.recorded')
            ->by($staff)
            ->on($entry)
            ->forOrganization($entry->organization_id)
            ->withMetadata(['scope' => $entry->scope->value])
            ->write();

        return back()->with('status', __('intelligence.costs.saved'));
    }

    public function update(Request $request, CurrentActor $actor, CostEntry $entry): RedirectResponse
    {
        $this->refuseUnless($actor);

        $data = $this->validated($request);
        $staff = $this->staff($actor);

        $entry->fill($this->columns($data))->save();

        Audit::action('intelligence.cost.changed')
            ->by($staff)
            ->on($entry)
            ->forOrganization($entry->organization_id)
            ->withMetadata(['scope' => $entry->scope->value])
            ->write();

        return back()->with('status', __('intelligence.costs.saved'));
    }

    public function destroy(CurrentActor $actor, CostEntry $entry): RedirectResponse
    {
        $this->refuseUnless($actor);

        $staff = $this->staff($actor);

        Audit::action('intelligence.cost.removed')
            ->by($staff)
            ->on($entry)
            ->forOrganization($entry->organization_id)
            ->write();

        $entry->delete();

        return back()->with('status', __('intelligence.costs.removed'));
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        return $request->validate([
            'label' => ['required', 'string', 'max:191'],
            'vendor' => ['nullable', 'string', 'max:191'],
            'scope' => ['required', Rule::enum(CostScope::class)],
            'subject_id' => ['nullable', 'string', 'max:40'],
            'currency_code' => ['required', 'string', 'size:3'],
            // Negative is refused: a cost that is income is a credit note,
            // and letting one in here would be a second way to move money.
            'amount_minor' => ['required', 'integer', 'min:0'],
            'period' => ['required', Rule::enum(CostPeriod::class)],
            'strategy' => ['required', Rule::enum(AllocationStrategy::class)],
            'metric' => ['nullable', Rule::enum(MetricKind::class)],
            'starts_on' => ['nullable', 'date'],
            'ends_on' => ['nullable', 'date', 'after_or_equal:starts_on'],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function columns(array $data): array
    {
        $scope = CostScope::from((string) $data['scope']);
        $strategy = AllocationStrategy::from((string) $data['strategy']);

        // `validate()` returns only the keys that were submitted, so a field
        // the form left empty is absent rather than null.
        $subject = $data['subject_id'] ?? null;

        return [
            'label' => $data['label'],
            'vendor' => $data['vendor'] ?? null,
            'scope' => $scope,
            'subject_type' => $scope->needsSubject() && is_string($subject) && $subject !== ''
                ? $this->subjectType($scope)
                : null,
            'subject_id' => $scope->needsSubject() && is_string($subject) && $subject !== ''
                ? $subject
                : null,
            'currency_code' => strtoupper((string) $data['currency_code']),
            'amount_minor' => (int) $data['amount_minor'],
            'period' => CostPeriod::from((string) $data['period']),
            'strategy' => $strategy,
            // A metric on an even split is a field stored and read by
            // nothing, which is the trap the tax screen shipped three of.
            'metric' => $strategy->needsMetric() && isset($data['metric'])
                ? MetricKind::from((string) $data['metric'])
                : null,
            'starts_on' => isset($data['starts_on']) ? CarbonImmutable::parse((string) $data['starts_on']) : null,
            'ends_on' => isset($data['ends_on']) ? CarbonImmutable::parse((string) $data['ends_on']) : null,
            'note' => $data['note'] ?? null,
        ];
    }

    private function subjectType(CostScope $scope): string
    {
        return match ($scope) {
            CostScope::Product => Product::class,
            // A licence is named against whichever it follows, and a server
            // is the ordinary case.
            default => Server::class,
        };
    }

    /**
     * Metrics an operator could sensibly weight a cost by.
     *
     * Every one core knows, because what a machine is really sold by is the
     * operator's own answer — and a short list chosen here would be core
     * deciding it for them.
     *
     * @return list<array{value: string, label: string}>
     */
    private function metrics(): array
    {
        return array_map(
            static fn (MetricKind $metric): array => [
                'value' => $metric->value,
                'label' => app(MetricNames::class)->label($metric),
            ],
            MetricKind::cases(),
        );
    }

    /**
     * @param  list<CostEntry>  $entries
     * @return list<array<string, mixed>>
     */
    private function rows(array $entries): array
    {
        $metrics = app(MetricNames::class);

        return array_map(
            static fn (CostEntry $entry): array => [
                'id' => $entry->id,
                'label' => $entry->label,
                'vendor' => $entry->vendor,
                'scope' => $entry->scope->value,
                'scopeLabel' => (string) __($entry->scope->labelKey()),
                'subjectId' => $entry->subject_id,
                'subject' => $entry->subject?->getAttribute('name'),
                'currency' => $entry->currency_code,
                'amountMinor' => $entry->amount_minor,
                'amount' => $entry->amount->format(app()->getLocale()),
                'period' => $entry->period->value,
                'periodLabel' => (string) __($entry->period->labelKey()),
                'strategy' => $entry->strategy->value,
                'strategyLabel' => (string) __($entry->strategy->labelKey()),
                'metric' => $entry->metric?->value,
                // Worded, never the raw key: `MetricKind`'s values have dots
                // in them, and every screen in this product that printed one
                // printed its own translation key for four phases.
                'metricLabel' => $entry->metric === null
                    ? null
                    : $metrics->label($entry->metric),
                'startsOn' => $entry->starts_on?->toDateString(),
                'endsOn' => $entry->ends_on?->toDateString(),
                'note' => $entry->note,
            ],
            $entries,
        );
    }

    private function staff(CurrentActor $actor): StaffUser
    {
        $staff = $actor->model();

        if (! $staff instanceof StaffUser) {
            throw new ForbiddenException(__('automation.errors.not_permitted'));
        }

        return $staff;
    }

    private function refuseUnless(CurrentActor $actor): void
    {
        if (! $actor->can('intelligence.costs.manage')) {
            throw new ForbiddenException(__('automation.errors.not_permitted'));
        }
    }
}
