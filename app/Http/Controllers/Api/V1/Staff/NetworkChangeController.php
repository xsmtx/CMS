<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Staff;

use App\Application\Network\DecideNetworkChange;
use App\Domain\Network\Exceptions\ChangeRefused;
use App\Domain\Network\NetworkChangeState;
use App\Http\Controllers\Controller;
use App\Infrastructure\Identity\Models\StaffUser;
use App\Infrastructure\Network\Models\NetworkChange;
use App\Support\Errors\ForbiddenException;
use App\Support\Errors\ValidationFailedException;
use App\Support\Identity\CurrentActor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Agreeing to a device change, from a phone (§26).
 *
 * **Deciding, never applying**, and that split is the whole reason this is on
 * the staff API at all. `network.changes.apply` carries the password
 * challenge, pushes a configuration to a box, backs it up, verifies it and
 * rolls back — and `ApplyNetworkChangeJob` runs with `tries = 1` because a
 * device configuration is not idempotent. None of that belongs behind a
 * bearer token in a pocket.
 *
 * Approving is a person saying yes, which is exactly what §26 asks a phone to
 * be able to do at two in the morning — and `DecideNetworkChange` still
 * refuses a change its own requester tries to approve, because a permission
 * can say who may approve and cannot say *whose* change it is.
 *
 * The diff comes down in full. An approval given against a summary is an
 * approval of something nobody read.
 */
final class NetworkChangeController extends Controller
{
    public function __construct(
        private readonly DecideNetworkChange $decisions,
        private readonly CurrentActor $actor,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $pending = $request->boolean('pending', true);

        $changes = NetworkChange::query()
            ->with(['device', 'requester'])
            ->when($pending, fn ($query) => $query->where('state', NetworkChangeState::Requested->value))->latest()
            ->orderByDesc('id')
            ->limit(100)
            ->get();

        return response()->json([
            'data' => $changes->map(fn (NetworkChange $change): array => [
                'id' => $change->id,
                'summary' => $change->summary,
                'reason' => $change->reason,
                'ticket' => $change->ticket,
                'device' => $change->device?->label,
                'state' => $change->state->value,
                'stateLabel' => (string) __($change->state->labelKey()),
                'requestedBy' => $change->requester?->name,
                // In full, and never summarised: an approval given against a
                // summary is an approval of something nobody read.
                'diff' => $change->diff,
                'requiresApproval' => $change->requires_approval,
                'requestedAt' => $change->created_at?->toIso8601String(),
            ])->values(),
        ]);
    }

    public function decide(Request $request, NetworkChange $change): JsonResponse
    {
        $validated = $request->validate([
            'decision' => ['required', 'string', 'in:approve,reject'],
            // A rejection with no reason is a rejection somebody has to come
            // and ask about.
            'note' => ['nullable', 'string', 'max:2000'],
        ]);

        $staff = $this->actor->model();

        if (! $staff instanceof StaffUser) {
            throw new ForbiddenException((string) __('api.errors.forbidden'));
        }

        try {
            $validated['decision'] === 'approve'
                ? $this->decisions->approve($change, $staff, $validated['note'] ?? null)
                : $this->decisions->reject($change, $staff, $validated['note'] ?? null);
        } catch (ChangeRefused $refused) {
            // A change one person both asked for and agreed to is a change
            // nobody agreed to, and that is a caller's mistake rather than a
            // failure — so a 422 with the sentence, never a 500.
            throw new ValidationFailedException($refused->worded());
        }

        return response()->json(['data' => ['state' => $change->refresh()->state->value]]);
    }
}
