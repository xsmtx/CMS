<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Application\Reliability\Incidents;
use App\Application\Reports\MoneyByCurrency;
use App\Domain\Reliability\AlertSeverity;
use App\Domain\Reliability\Exceptions\IncidentRefused;
use App\Domain\Reliability\IncidentState;
use App\Http\Controllers\Controller;
use App\Http\Requests\Reliability\IncidentUpdateRequest;
use App\Http\Requests\Reliability\OpenIncidentRequest;
use App\Infrastructure\Identity\Models\StaffUser;
use App\Infrastructure\Reliability\Models\Alert;
use App\Infrastructure\Reliability\Models\Incident;
use App\Infrastructure\Reliability\Models\IncidentUpdate;
use App\Support\Errors\ForbiddenException;
use App\Support\Identity\CurrentActor;
use App\Support\Organizations\OrganizationContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Incidents, their timeline, and what was underneath.
 *
 * **Opening one is Support's**, which is the decision worth stating. The
 * person answering "is it just me?" is the person who finds out first, and a
 * platform where they had to ask somebody senior to press the button is a
 * platform where the first ten minutes of an outage are spent looking for
 * that person.
 *
 * **There is no route that changes the state alone.** Moving to `identified`
 * without saying what was identified is the move that makes a status page
 * useless, so the update carries both and the use case refuses anything else.
 */
final class IncidentController extends Controller
{
    public function index(Request $request, CurrentActor $actor): Response
    {
        $this->refuseUnless($actor, 'reliability.incidents.view');

        $showAll = $request->boolean('all');

        $incidents = Incident::query()
            ->with(['opener', 'impact'])
            ->withCount('alerts')
            ->unless($showAll, static fn ($query) => $query->open())
            ->latest('started_at')
            ->paginate(25)
            ->withQueryString();

        return Inertia::render('Admin/Reliability/Incidents', [
            'incidents' => [
                'data' => array_map($this->row(...), array_values($incidents->items())),
                'links' => $incidents->linkCollection()->toArray(),
                'currentPage' => $incidents->currentPage(),
                'lastPage' => $incidents->lastPage(),
                'total' => $incidents->total(),
            ],
            'filters' => ['all' => $showAll],
            'severities' => $this->severities(),
            'can' => ['manage' => $actor->can('reliability.incidents.manage')],
        ]);
    }

    public function show(Incident $incident, CurrentActor $actor): Response
    {
        $this->refuseUnless($actor, 'reliability.incidents.view');

        $incident->load(['opener', 'impact', 'updates.author', 'alerts.rule']);

        return Inertia::render('Admin/Reliability/Incident', [
            'incident' => $this->row($incident) + [
                'summary' => $incident->summary,
                'postmortem' => $incident->postmortem,
                'updates' => array_values($incident->updates
                    ->map(static fn (IncidentUpdate $update): array => [
                        'id' => $update->id,
                        'body' => $update->body,
                        'state' => $update->state->value,
                        'stateLabel' => (string) __($update->state->labelKey()),
                        'stateTone' => $update->state->tone(),
                        'author' => $update->author?->name,
                        'isPublic' => $update->is_public,
                        'writtenAt' => $update->created_at?->toIso8601String(),
                    ])
                    ->all()),
                'alerts' => array_values($incident->alerts
                    ->map(static fn (Alert $alert): array => [
                        'id' => $alert->id,
                        'subject' => $alert->subject_label,
                        'rule' => $alert->rule?->name,
                        'observed' => $alert->observed,
                        'severityTone' => $alert->severity->tone(),
                        'severityLabel' => (string) __($alert->severity->labelKey()),
                    ])
                    ->all()),
            ],
            'states' => $this->states(),
            // Open alerts nobody has attached yet, so the one action an
            // operator wants in the first minute is one press away.
            'unattached' => array_values(Alert::query()
                ->open()
                ->whereNull('incident_id')
                ->with('rule')
                ->latest('last_seen_at')
                ->limit(50)
                ->get()
                ->map(static fn (Alert $alert): array => [
                    'id' => $alert->id,
                    'subject' => $alert->subject_label,
                    'rule' => $alert->rule?->name,
                ])
                ->all()),
            'can' => ['manage' => $actor->can('reliability.incidents.manage')],
        ]);
    }

    public function store(OpenIncidentRequest $request, CurrentActor $actor, Incidents $incidents): RedirectResponse
    {
        $this->refuseUnless($actor, 'reliability.incidents.manage');

        $data = $request->validated();

        $incident = $incidents->open(
            organizationId: (string) app(OrganizationContext::class)->id(),
            title: $data['title'],
            body: $data['body'],
            severity: AlertSeverity::from($data['severity']),
            actor: $this->staff($actor),
            startedAt: isset($data['started_at'])
                ? CarbonImmutable::parse($data['started_at'])
                : null,
            public: (bool) ($data['is_public'] ?? false),
        );

        return to_route('admin.reliability.incidents.show', $incident)
            ->with('status', __('reliability.incidents.opened'));
    }

    public function update(
        IncidentUpdateRequest $request,
        Incident $incident,
        CurrentActor $actor,
        Incidents $incidents,
    ): RedirectResponse {
        $this->refuseUnless($actor, 'reliability.incidents.manage');

        $data = $request->validated();
        $state = IncidentState::from($data['state']);
        $public = (bool) ($data['is_public'] ?? false);

        try {
            $state === IncidentState::Resolved
                ? $incidents->resolve($incident, $data['body'], $this->staff($actor), $public)
                : $incidents->note($incident, $data['body'], $state, $this->staff($actor), $public);
        } catch (IncidentRefused $refusal) {
            return back()->withErrors(['body' => $refusal->getMessage()]);
        }

        return back()->with('status', __('reliability.incidents.noted'));
    }

    public function attach(Request $request, Incident $incident, CurrentActor $actor, Incidents $incidents): RedirectResponse
    {
        $this->refuseUnless($actor, 'reliability.incidents.manage');

        $alert = Alert::query()->whereKey($request->string('alert')->toString())->first();

        if (! $alert instanceof Alert) {
            abort(404);
        }

        try {
            $incidents->attach($incident, $alert, $this->staff($actor));
        } catch (IncidentRefused $refusal) {
            return back()->withErrors(['alert' => $refusal->getMessage()]);
        }

        return back()->with('status', __('reliability.incidents.attached'));
    }

    public function detach(Alert $alert, CurrentActor $actor, Incidents $incidents): RedirectResponse
    {
        $this->refuseUnless($actor, 'reliability.incidents.manage');

        try {
            $incidents->detach($alert, $this->staff($actor));
        } catch (IncidentRefused $refusal) {
            return back()->withErrors(['alert' => $refusal->getMessage()]);
        }

        return back()->with('status', __('reliability.incidents.detached'));
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    private function severities(): array
    {
        return array_values(array_map(
            static fn (AlertSeverity $severity): array => [
                'value' => $severity->value,
                'label' => (string) __($severity->labelKey()),
            ],
            AlertSeverity::cases(),
        ));
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    private function states(): array
    {
        return array_values(array_map(
            static fn (IncidentState $state): array => [
                'value' => $state->value,
                'label' => (string) __($state->labelKey()),
            ],
            IncidentState::cases(),
        ));
    }

    /**
     * @return array<string, mixed>
     */
    private function row(Incident $incident): array
    {
        $impact = $incident->impact;

        return [
            'id' => $incident->id,
            'reference' => $incident->reference,
            'title' => $incident->title,
            // Two fields, always, plus the tone the server decided: a second
            // mapping in the browser would be a second place to get it wrong.
            'state' => $incident->state->value,
            'stateLabel' => (string) __($incident->state->labelKey()),
            'stateTone' => $incident->state->tone(),
            'severity' => $incident->severity->value,
            'severityLabel' => (string) __($incident->severity->labelKey()),
            'severityTone' => $incident->severity->tone(),
            'openedBy' => $incident->opener?->name,
            'startedAt' => $incident->started_at->toIso8601String(),
            'detectedAt' => $incident->detected_at?->toIso8601String(),
            'resolvedAt' => $incident->resolved_at?->toIso8601String(),
            'durationSeconds' => $incident->durationSeconds(),
            'isPublic' => $incident->is_public,
            'alertCount' => $incident->alerts_count ?? $incident->alerts()->count(),
            'impact' => $impact === null ? null : [
                'services' => $impact->services,
                'customers' => $impact->customers,
                // The row stores minor units and a currency, never a
                // formatted string: a sentence frozen in whichever locale
                // resolved the incident is a sentence the next operator
                // cannot read. The decimal point is put in here, once,
                // through the one class that knows a yen has none.
                'recurring' => $this->money($impact->recurring),
                'frozenAt' => $impact->frozen_at->toIso8601String(),
            ],
        ];
    }

    /**
     * @param  list<array{currency: string, minor: int}>  $rows
     * @return list<array{currency: string, amount: string, minor: int}>
     */
    private function money(array $rows): array
    {
        $money = new MoneyByCurrency;

        foreach ($rows as $row) {
            $money->add($row['currency'], $row['minor']);
        }

        return $money->toArray(app()->getLocale());
    }

    private function staff(CurrentActor $actor): ?StaffUser
    {
        $staff = $actor->model();

        return $staff instanceof StaffUser ? $staff : null;
    }

    private function refuseUnless(CurrentActor $actor, string $permission): void
    {
        if (! $actor->can($permission)) {
            throw new ForbiddenException(__('automation.errors.not_permitted'));
        }
    }
}
