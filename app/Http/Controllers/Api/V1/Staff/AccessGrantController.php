<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Staff;

use App\Application\Network\AccessGrants;
use App\Domain\Network\Exceptions\GrantRefused;
use App\Domain\Network\GrantableCapability;
use App\Http\Controllers\Controller;
use App\Infrastructure\Identity\Models\StaffUser;
use App\Infrastructure\Network\Models\AccessGrant;
use App\Support\Errors\ForbiddenException;
use App\Support\Errors\ValidationFailedException;
use App\Support\Identity\CurrentActor;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Just-in-time access, asked for and given from a phone (§26).
 *
 * The one place on this surface where a phone is genuinely the right tool:
 * somebody is standing at a console at three in the morning and the person
 * who can grant it is in bed. That is the whole case for §17.
 *
 * Three rules carry over from `AccessGrants` unchanged, and none of them is
 * re-implemented here:
 *
 * **Nobody grants themselves anything.** A permission says who may grant and
 * cannot say *to whom*, so the refusal lives in the use case.
 *
 * **A grant only ever adds.** `GrantableCapability` has no member that takes
 * something away and must not gain one — a mechanism that could remove a
 * permission for a window is a mechanism for locking an operator out.
 *
 * **The window is bounded at both ends.** Under five minutes is a grant
 * somebody is about to give again; over the configured maximum is a
 * permission with extra steps, and this product has roles for those.
 */
final class AccessGrantController extends Controller
{
    public function __construct(
        private readonly AccessGrants $grants,
        private readonly CurrentActor $actor,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $live = $request->boolean('live', true);
        $now = CarbonImmutable::now();

        $rows = AccessGrant::query()
            ->with(['holder', 'granter'])
            ->when($live, fn ($query) => $query
                ->whereNull('revoked_at')
                ->where('expires_at', '>', $now))
            ->latest('expires_at')
            ->limit(100)
            ->get();

        return response()->json([
            'data' => $rows->map(fn (AccessGrant $grant): array => [
                'id' => $grant->id,
                'holder' => $grant->holder?->name,
                'granter' => $grant->granter?->name,
                'capability' => $grant->capability->value,
                'capabilityLabel' => (string) __($grant->capability->labelKey()),
                'reason' => $grant->reason,
                'ticket' => $grant->ticket,
                'expiresAt' => $grant->expires_at->toIso8601String(),
                'revokedAt' => $grant->revoked_at?->toIso8601String(),
                /*
                 * Whether it is live is a question about its own two
                 * timestamps, asked when somebody asks it — there is no
                 * state column, so a scheduler that was down for three hours
                 * cannot leave somebody holding access they should not.
                 */
                'live' => $grant->revoked_at === null && $grant->expires_at->isAfter($now),
            ])->values(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'holder_id' => ['required', 'string'],
            'capability' => ['required', 'string', Rule::in(array_column(GrantableCapability::cases(), 'value'))],
            // A grant with no reason is a grant nobody can review afterwards.
            'reason' => ['required', 'string', 'min:5', 'max:500'],
            'minutes' => ['required', 'integer', 'min:5'],
            'ticket' => ['nullable', 'string', 'max:64'],
        ]);

        $granter = $this->actor->model();

        if (! $granter instanceof StaffUser) {
            throw new ForbiddenException((string) __('api.errors.forbidden'));
        }

        // Narrowed by the boundary, so a grant cannot be handed to somebody
        // in another organization by putting their id in the payload.
        $holder = StaffUser::query()->find($validated['holder_id']);

        if (! $holder instanceof StaffUser) {
            throw new ValidationFailedException((string) __('access.errors.unknown_holder'));
        }

        try {
            $grant = $this->grants->grant(
                holder: $holder,
                capability: GrantableCapability::from($validated['capability']),
                granter: $granter,
                reason: $validated['reason'],
                expiresAt: CarbonImmutable::now()->addMinutes((int) $validated['minutes']),
                ticket: $validated['ticket'] ?? null,
            );
        } catch (GrantRefused $refused) {
            // Granting yourself something, or a window at either end of the
            // bounds. All three are a caller's mistake.
            throw new ValidationFailedException($refused->getMessage());
        }

        return response()->json([
            'data' => [
                'id' => $grant->id,
                'expiresAt' => $grant->expires_at->toIso8601String(),
            ],
        ], 201);
    }
}
