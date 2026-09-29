<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Staff;

use App\Application\Reliability\Incidents;
use App\Domain\Reliability\Exceptions\IncidentRefused;
use App\Domain\Reliability\IncidentState;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Staff\IncidentUpdateRequest;
use App\Infrastructure\Identity\Models\StaffUser;
use App\Infrastructure\Reliability\Models\Incident;
use App\Infrastructure\Reliability\Models\IncidentUpdate;
use App\Support\Errors\ValidationFailedException;
use App\Support\Identity\CurrentActor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Incidents, and the two things §26 asks a phone to do to one.
 *
 * Posting an update and resolving, both through `Incidents` — the same use
 * case the admin screen calls, so the state moves in one place, the timeline
 * is written by one writer and the impact is frozen by one freezer. If the
 * API had its own path to any of that, the two would eventually disagree
 * about what an incident is.
 *
 * **Opening one is not here.** §26 asks the staff app to acknowledge, assign
 * and escalate — all of which this product expresses as updates on an
 * incident somebody has already opened — and opening is the act with a
 * severity, a start time and a public flag to choose. That is a form, and a
 * form at two in the morning on a phone is how an incident gets opened with
 * the wrong severity. It stays on the web, deliberately.
 */
final class IncidentController extends Controller
{
    public function __construct(
        private readonly Incidents $incidents,
        private readonly CurrentActor $actor,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $open = $request->boolean('open', true);

        $incidents = Incident::query()
            ->when($open, fn ($query) => $query->where('state', '!=', IncidentState::Resolved->value))
            ->latest('started_at')
            ->orderByDesc('id')
            ->limit(100)
            ->get();

        return response()->json([
            'data' => $incidents->map($this->row(...))->values(),
        ]);
    }

    public function show(Incident $incident): JsonResponse
    {
        $incident->load(['updates', 'alerts', 'impact']);

        return response()->json([
            'data' => $this->row($incident) + [
                'summary' => $incident->summary,
                'timeline' => $incident->updates->map(fn (IncidentUpdate $update): array => [
                    'id' => $update->id,
                    'body' => $update->body,
                    'state' => $update->state->value,
                    'stateLabel' => (string) __($update->state->labelKey()),
                    'public' => $update->is_public,
                    'at' => $update->created_at?->toIso8601String(),
                ])->values(),
                'alerts' => $incident->alerts->pluck('id')->values(),
                'impact' => $incident->impact === null ? null : [
                    'customers' => $incident->impact->customers,
                    'services' => $incident->impact->services,
                ],
            ],
        ]);
    }

    public function update(IncidentUpdateRequest $request, Incident $incident): JsonResponse
    {
        try {
            $this->incidents->note(
                incident: $incident,
                body: $request->string('body')->toString(),
                state: IncidentState::from($request->string('state')->toString()),
                actor: $this->staff(),
                public: $request->boolean('public'),
            );
        } catch (IncidentRefused $refused) {
            // The refusals are a caller's mistake rather than a failure, so
            // they come back as a 422 with the sentence the operator would
            // have read on the screen — never as a 500.
            throw new ValidationFailedException($refused->worded());
        }

        return response()->json(['data' => $this->row($incident->refresh())]);
    }

    public function resolve(IncidentUpdateRequest $request, Incident $incident): JsonResponse
    {
        try {
            $this->incidents->resolve(
                incident: $incident,
                body: $request->string('body')->toString(),
                actor: $this->staff(),
                public: $request->boolean('public'),
            );
        } catch (IncidentRefused $refused) {
            throw new ValidationFailedException($refused->worded());
        }

        return response()->json(['data' => $this->row($incident->refresh())]);
    }

    /**
     * @return array<string, mixed>
     */
    private function row(Incident $incident): array
    {
        return [
            'id' => $incident->id,
            'reference' => $incident->reference,
            'title' => $incident->title,
            'state' => $incident->state->value,
            'stateLabel' => (string) __($incident->state->labelKey()),
            'stateTone' => $incident->state->tone(),
            'severity' => $incident->severity->value,
            'severityLabel' => (string) __($incident->severity->labelKey()),
            'public' => $incident->is_public,
            // When the customer's world broke, and when anybody found out.
            // The gap between them is what a postmortem is usually about.
            'startedAt' => $incident->started_at->toIso8601String(),
            'detectedAt' => $incident->detected_at?->toIso8601String(),
            'resolvedAt' => $incident->resolved_at?->toIso8601String(),
        ];
    }

    private function staff(): ?StaffUser
    {
        $model = $this->actor->model();

        return $model instanceof StaffUser ? $model : null;
    }
}
