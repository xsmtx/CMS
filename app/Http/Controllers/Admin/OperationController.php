<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Application\Operations\Operations;
use App\Domain\Operations\OperationState;
use App\Domain\Operations\OperationType;
use App\Http\Controllers\Controller;
use App\Infrastructure\Operations\Models\Operation;
use App\Support\Audit\Facades\Audit;
use App\Support\Errors\ForbiddenException;
use App\Support\Identity\CurrentActor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The Background Operations Center.
 *
 * Opens on the rows that need attention rather than on everything, because
 * an operator who has to filter before they can see a problem will open
 * this screen less often than they should.
 *
 * "Try again" puts the operation back on the queue without resetting its
 * attempt counter. Somebody retrying a thing that has already failed three
 * times should be able to see that it is on its fourth.
 */
final class OperationController extends Controller
{
    public function __construct(
        private readonly CurrentActor $actor,
        private readonly Operations $operations,
    ) {}

    public function index(Request $request): Response
    {
        $this->authorizeFor('operations.view');

        $state = OperationState::tryFrom($request->string('state')->toString());
        $type = OperationType::tryFrom($request->string('type')->toString());

        $operations = Operation::query()
            ->when($state instanceof OperationState, fn ($query) => $query->where('state', $state?->value))
            ->when($type instanceof OperationType, fn ($query) => $query->where('type', $type?->value))
            ->when(
                ! $state instanceof OperationState && ! $type instanceof OperationType,
                // The default view is the problem list, not the history.
                fn ($query) => $query->needingAttention(),
            )
            ->latest('created_at')
            ->paginate(25)
            ->withQueryString();

        return Inertia::render('Admin/Operations/Index', [
            'operations' => [
                'data' => $operations->getCollection()
                    ->map(fn (Operation $operation): array => $this->row($operation))
                    ->values()
                    ->all(),
                'currentPage' => $operations->currentPage(),
                'lastPage' => $operations->lastPage(),
                'total' => $operations->total(),
            ],
            'filters' => [
                'state' => $state?->value,
                'type' => $type?->value,
            ],
            'states' => array_map(
                static fn (OperationState $case): array => [
                    'value' => $case->value,
                    'label' => (string) __($case->labelKey()),
                ],
                OperationState::cases(),
            ),
            'types' => array_map(
                static fn (OperationType $case): array => [
                    'value' => $case->value,
                    'label' => (string) __($case->labelKey()),
                ],
                OperationType::cases(),
            ),
            'attention' => Operation::query()->needingAttention()->count(),
            'can' => ['manage' => $this->actor->can('operations.manage')],
        ]);
    }

    public function retry(Operation $operation): RedirectResponse
    {
        $this->authorizeFor('operations.manage');

        if (! $operation->state->canRetry()) {
            return back()->withErrors(['operation' => __('operations.cannot_retry')]);
        }

        $this->operations->requeue($operation);

        Audit::action('operations.retried')
            ->by($this->actor->model())
            ->on($operation)
            ->forOrganization($operation->organization_id)
            ->write();

        // The retry sweep picks it up within the next few minutes. Not
        // dispatched here: this controller does not know which job the
        // operation belongs to, and guessing would be worse than waiting.
        return back()->with('status', __('operations.retried'));
    }

    public function resolve(Operation $operation): RedirectResponse
    {
        $this->authorizeFor('operations.manage');

        $this->operations->resolve($operation, $this->actor->model());

        Audit::action('operations.resolved')
            ->by($this->actor->model())
            ->on($operation)
            ->forOrganization($operation->organization_id)
            ->write();

        return back()->with('status', __('operations.resolved'));
    }

    /**
     * @return array<string, mixed>
     */
    private function row(Operation $operation): array
    {
        return [
            'id' => $operation->id,
            'type' => $operation->type->value,
            'typeLabel' => (string) __($operation->type->labelKey()),
            'state' => $operation->state->value,
            'stateLabel' => (string) __($operation->state->labelKey()),
            'subject' => $operation->subject_label,
            'attempt' => $operation->attempt,
            'maxAttempts' => $operation->max_attempts,
            'progress' => $operation->progress,
            'startedAt' => $operation->started_at?->toIso8601String(),
            'finishedAt' => $operation->finished_at?->toIso8601String(),
            'nextAttemptAt' => $operation->next_attempt_at?->toIso8601String(),
            'resolvedAt' => $operation->resolved_at?->toIso8601String(),
            'needsAttention' => $operation->state->needsAttention() && $operation->resolved_at === null,
            'canRetry' => $operation->state->canRetry(),
            // Already redacted on the way in. Shown because an operator
            // cannot act on "something went wrong".
            'error' => $operation->error,
            'createdAt' => $operation->created_at?->toIso8601String(),
        ];
    }

    private function authorizeFor(string $permission): void
    {
        if (! $this->actor->can($permission)) {
            throw new ForbiddenException(__('operations.errors.not_permitted'));
        }
    }
}
