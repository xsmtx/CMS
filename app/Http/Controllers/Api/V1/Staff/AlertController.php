<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Staff;

use App\Domain\Reliability\AlertState;
use App\Http\Controllers\Controller;
use App\Infrastructure\Reliability\Models\Alert;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * What the platform has noticed (§26).
 *
 * Read only, and there is deliberately no acknowledge here: Phase D decided
 * this product has no `acknowledged` state at all, because an operator asked
 * to acknowledge four hundred disk warnings a week learns to acknowledge
 * without reading. The human act is **opening an incident**, which is what
 * `IncidentController` offers.
 */
final class AlertController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $open = $request->boolean('open', true);

        $alerts = Alert::query()
            ->with('rule')
            ->when($open, fn ($query) => $query->whereNull('cleared_at'))
            ->latest('first_seen_at')
            ->orderByDesc('id')
            ->limit(100)
            ->get();

        return response()->json([
            'data' => $alerts->map(fn (Alert $alert): array => [
                'id' => $alert->id,
                'rule' => $alert->rule?->name,
                'subject' => $alert->subject_label,
                'subjectKey' => $alert->subject_key,
                /*
                 * Two fields for each of the two words, always: the value
                 * for the tone and the label for the screen. A phone tones a
                 * row exactly as the admin area does, from the same pair.
                 */
                'severity' => $alert->severity->value,
                'severityLabel' => (string) __($alert->severity->labelKey()),
                'state' => $alert->state->value,
                'stateLabel' => (string) __($alert->state->labelKey()),
                'observed' => $alert->observed,
                'occurrences' => $alert->occurrences,
                'firstSeenAt' => $alert->first_seen_at->toIso8601String(),
                'lastSeenAt' => $alert->last_seen_at->toIso8601String(),
                'clearedAt' => $alert->cleared_at?->toIso8601String(),
                // A maintenance window suppresses the message, never the
                // observation — so a held alert is on this list saying which
                // window held it, rather than missing from it.
                'suppressedBy' => $alert->state === AlertState::Suppressed ? $alert->suppressed_by : null,
                'incidentId' => $alert->incident_id,
            ])->values(),
        ]);
    }
}
