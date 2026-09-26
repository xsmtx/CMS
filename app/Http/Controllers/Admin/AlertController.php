<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Reliability\AlertComparison;
use App\Domain\Reliability\AlertSeverity;
use App\Domain\Reliability\AlertSubject;
use App\Http\Controllers\Controller;
use App\Http\Requests\Reliability\AlertRuleRequest;
use App\Infrastructure\Reliability\Models\Alert;
use App\Infrastructure\Reliability\Models\AlertRule;
use App\Support\Audit\Facades\Audit;
use App\Support\Errors\ForbiddenException;
use App\Support\Identity\CurrentActor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * What is currently wrong, and the rules that decide it.
 *
 * One screen for both, because they are the same subject read two ways and a
 * separate "rules" page would be a page an operator visits once. The list is
 * the thing somebody opens at three in the morning; the rules are what they
 * edit afterwards, and having them here means the edit happens with the
 * consequence on screen.
 *
 * **An empty list is the good outcome and says so.** A screen that looked
 * broken when nothing was wrong would be a screen people check by breaking
 * something.
 */
final class AlertController extends Controller
{
    public function index(Request $request, CurrentActor $actor): Response
    {
        $this->refuseUnless($actor, 'reliability.alerts.view');

        $showAll = $request->boolean('all');

        $alerts = Alert::query()
            ->with('rule')
            ->unless($showAll, static fn ($query) => $query->open())
            ->orderByRaw("field(severity, 'emergency', 'critical', 'warning')")
            ->latest('last_seen_at')
            ->paginate(25)
            ->withQueryString();

        return Inertia::render('Admin/Reliability/Alerts', [
            'alerts' => [
                'data' => array_map($this->row(...), array_values($alerts->items())),
                'links' => $alerts->linkCollection()->toArray(),
                'currentPage' => $alerts->currentPage(),
                'lastPage' => $alerts->lastPage(),
                'total' => $alerts->total(),
            ],
            'filters' => ['all' => $showAll],
            'rules' => array_values(AlertRule::query()
                ->withCount(['alerts' => static fn ($query) => $query->whereNull('cleared_at')])
                ->orderBy('name')
                ->get()
                ->map($this->ruleRow(...))
                ->all()),
            'options' => [
                'subjects' => $this->options(AlertSubject::cases()),
                'severities' => $this->options(AlertSeverity::cases()),
                'comparisons' => $this->options(AlertComparison::cases()),
            ],
            'can' => ['manage' => $actor->can('reliability.alerts.manage')],
        ]);
    }

    public function store(AlertRuleRequest $request, CurrentActor $actor): RedirectResponse
    {
        $this->refuseUnless($actor, 'reliability.alerts.manage');

        $rule = AlertRule::query()->create($request->columns());

        Audit::action('reliability.rule.created')
            ->by($actor->model())
            ->on($rule)
            ->forOrganization($rule->organization_id)
            ->withMetadata(['subject' => $rule->subject->value, 'target' => $rule->target])
            ->write();

        return back()->with('status', __('reliability.rules.saved'));
    }

    public function update(AlertRuleRequest $request, AlertRule $rule, CurrentActor $actor): RedirectResponse
    {
        $this->refuseUnless($actor, 'reliability.alerts.manage');

        $rule->fill($request->columns())->save();

        Audit::action('reliability.rule.updated')
            ->by($actor->model())
            ->on($rule)
            ->forOrganization($rule->organization_id)
            ->changedFrom($rule)
            ->write();

        return back()->with('status', __('reliability.rules.saved'));
    }

    /**
     * Delete the rule, keep what it raised.
     *
     * The alerts go with it through the cascade only because they are
     * meaningless without the question that asked them — but the **row** for
     * a resolved incident that referenced one is not, which is why an alert
     * points at an incident and never the other way round.
     */
    public function destroy(AlertRule $rule, CurrentActor $actor): RedirectResponse
    {
        $this->refuseUnless($actor, 'reliability.alerts.manage');

        Audit::action('reliability.rule.deleted')
            ->by($actor->model())
            ->on($rule)
            ->forOrganization($rule->organization_id)
            ->write();

        $rule->delete();

        return back()->with('status', __('reliability.rules.deleted'));
    }

    /**
     * @param  list<AlertSubject|AlertSeverity|AlertComparison>  $cases
     * @return list<array{value: string, label: string}>
     */
    private function options(array $cases): array
    {
        return array_values(array_map(
            static fn (AlertSubject|AlertSeverity|AlertComparison $case): array => [
                'value' => $case->value,
                'label' => (string) __($case->labelKey()),
            ],
            $cases,
        ));
    }

    /**
     * @return array<string, mixed>
     */
    private function row(Alert $alert): array
    {
        return [
            'id' => $alert->id,
            'subject' => $alert->subject_label,
            'subjectKey' => $alert->subject_key,
            'rule' => $alert->rule?->name,
            // Two fields, always: the tone from the value and the word from
            // the translation. Sending one of them is either untranslated or
            // untoned, which this product has learned five times.
            'severity' => $alert->severity->value,
            'severityLabel' => (string) __($alert->severity->labelKey()),
            'severityTone' => $alert->severity->tone(),
            'state' => $alert->state->value,
            'stateLabel' => (string) __($alert->state->labelKey()),
            'observed' => $alert->observed,
            'occurrences' => $alert->occurrences,
            'firstSeenAt' => $alert->first_seen_at->toIso8601String(),
            'lastSeenAt' => $alert->last_seen_at->toIso8601String(),
            'clearedAt' => $alert->cleared_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function ruleRow(AlertRule $rule): array
    {
        return [
            'id' => $rule->id,
            'name' => $rule->name,
            'subject' => $rule->subject->value,
            'subjectLabel' => (string) __($rule->subject->labelKey()),
            'target' => $rule->target,
            'comparison' => $rule->comparison?->value,
            'comparisonLabel' => $rule->comparison === null
                ? null
                : (string) __($rule->comparison->labelKey()),
            // The number an operator typed, back out of parts per million in
            // the one place that knows the unit.
            'threshold' => $rule->threshold(),
            'forMinutes' => $rule->for_minutes,
            'severity' => $rule->severity->value,
            'severityLabel' => (string) __($rule->severity->labelKey()),
            'enabled' => $rule->enabled,
            'notify' => $rule->notify,
            'note' => $rule->note,
            'openAlerts' => $rule->alerts_count ?? 0,
        ];
    }

    private function refuseUnless(CurrentActor $actor, string $permission): void
    {
        if (! $actor->can($permission)) {
            throw new ForbiddenException(__('automation.errors.not_permitted'));
        }
    }
}
