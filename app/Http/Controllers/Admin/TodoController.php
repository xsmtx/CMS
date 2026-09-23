<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Platform\TodoStatus;
use App\Http\Controllers\Controller;
use App\Infrastructure\Identity\Models\StaffUser;
use App\Infrastructure\Platform\Models\TodoItem;
use App\Support\Errors\ForbiddenException;
use App\Support\Identity\CurrentActor;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The list of things somebody meant to come back to.
 *
 * Not a ticket system and not a project tool: three states, an optional
 * date and an optional name. Anything more would be a worse ticket system
 * competing with the real one, and the thing this replaces is a sticky note
 * on a monitor.
 *
 * Not audited, deliberately. An audit trail exists to answer questions
 * about money, access and customer data; "who ticked off ring the
 * registrar" is not one of them, and filling the trail with them makes the
 * ones that matter harder to find.
 */
final class TodoController extends Controller
{
    public function __construct(private readonly CurrentActor $actor) {}

    public function index(Request $request): Response
    {
        $this->authorizeFor('platform.health.view');

        $showDone = $request->boolean('done');

        $items = TodoItem::query()
            ->with('assignee:id,name')
            ->unless($showDone, fn ($query) => $query->whereNot('status', TodoStatus::Done->value))
            // Undated last: a note with no date is not overdue, it is
            // simply undated, and sorting it first would bury the ones that
            // have a deadline.
            ->orderByRaw('due_on is null, due_on')->latest()
            ->get();

        return Inertia::render('Admin/Todo/Index', [
            'items' => array_values($items
                ->map(static fn (TodoItem $item): array => [
                    'id' => $item->id,
                    'title' => $item->title,
                    'body' => $item->body,
                    'status' => $item->status->value,
                    'statusLabel' => (string) __($item->status->labelKey()),
                    'dueOn' => $item->due_on?->toDateString(),
                    'overdue' => $item->status->isOpen()
                        && $item->due_on !== null
                        && $item->due_on->isPast(),
                    'assignedTo' => $item->assigned_to,
                    'assignee' => $item->assignee?->name,
                ])
                ->all()),
            'filters' => ['done' => $showDone],
            'statuses' => array_values(array_map(
                static fn (TodoStatus $status): array => [
                    'value' => $status->value,
                    'label' => (string) __($status->labelKey()),
                ],
                TodoStatus::cases(),
            )),
            'staff' => array_values(StaffUser::query()
                ->orderBy('name')
                ->get()
                ->map(static fn (StaffUser $member): array => [
                    'value' => $member->id,
                    'label' => $member->name,
                ])
                ->all()),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorizeFor('platform.health.view');

        $data = $this->validated($request);

        TodoItem::query()->create([
            'organization_id' => $this->actor->organizationId(),
            'title' => (string) $data['title'],
            'body' => $data['body'] ?? null,
            'due_on' => $data['due_on'] ?? null,
            'assigned_to' => $data['assigned_to'] ?? null,
            'created_by' => $this->actor->model()?->getKey(),
        ]);

        return back()->with('status', __('platform.todo.saved'));
    }

    public function update(Request $request, TodoItem $todo): RedirectResponse
    {
        $this->authorizeFor('platform.health.view');

        $data = $this->validated($request);
        $status = TodoStatus::tryFrom((string) ($data['status'] ?? '')) ?? $todo->status;

        $todo->update([
            'title' => (string) $data['title'],
            'body' => $data['body'] ?? null,
            'due_on' => $data['due_on'] ?? null,
            'assigned_to' => $data['assigned_to'] ?? null,
            'status' => $status->value,
            // Stamped when it is done and cleared when it is reopened, so
            // "when was this finished" never describes something that is
            // not finished.
            'completed_at' => $status === TodoStatus::Done ? CarbonImmutable::now() : null,
        ]);

        return back()->with('status', __('platform.todo.saved'));
    }

    public function destroy(TodoItem $todo): RedirectResponse
    {
        $this->authorizeFor('platform.health.view');

        $todo->delete();

        return back()->with('status', __('platform.todo.deleted'));
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        /** @var array<string, mixed> $data */
        $data = $request->validate([
            'title' => ['required', 'string', 'max:191'],
            'body' => ['nullable', 'string', 'max:5000'],
            'due_on' => ['nullable', 'date'],
            'assigned_to' => ['nullable', 'ulid', 'exists:staff_users,id'],
            'status' => ['nullable', 'string'],
        ]);

        return $data;
    }

    private function authorizeFor(string $permission): void
    {
        if (! $this->actor->can($permission)) {
            throw new ForbiddenException(__('errors.forbidden'));
        }
    }
}
