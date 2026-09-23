<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Infrastructure\Support\Models\CannedResponse;
use App\Infrastructure\Support\Models\Department;
use App\Support\Audit\Facades\Audit;
use App\Support\Errors\ForbiddenException;
use App\Support\Identity\CurrentActor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Predefined replies: the answer a desk gives forty times a week.
 *
 * They have existed since Phase 8 and the ticket screen has offered them
 * since, with no way to write one — which meant an installation shipped
 * with none and stayed that way. This is that screen.
 *
 * `used_count` is shown and never editable. It is the only honest way to
 * answer "which of these is worth keeping", and a number an operator could
 * type would answer nothing.
 */
final class CannedResponseController extends Controller
{
    public function __construct(private readonly CurrentActor $actor) {}

    public function index(): Response
    {
        $this->authorizeFor('support.tickets.view');

        return Inertia::render('Admin/Support/Replies', [
            'replies' => array_values(CannedResponse::query()
                ->with('department:id,name')
                ->orderByDesc('used_count')
                ->orderBy('name')
                ->get()
                ->map(static fn (CannedResponse $reply): array => [
                    'id' => $reply->id,
                    'name' => $reply->name,
                    'body' => $reply->body,
                    'departmentId' => $reply->department_id,
                    'department' => $reply->department?->name,
                    'usedCount' => $reply->used_count,
                ])
                ->all()),
            'departments' => array_values(Department::query()
                ->orderBy('name')
                ->get()
                ->map(static fn (Department $department): array => [
                    'value' => $department->id,
                    'label' => $department->name,
                ])
                ->all()),
            'can' => ['manage' => $this->actor->can('support.tickets.manage')],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorizeFor('support.tickets.manage');

        $data = $this->validated($request);

        $reply = CannedResponse::query()->create([
            'organization_id' => $this->actor->organizationId(),
            'name' => (string) $data['name'],
            'body' => (string) $data['body'],
            'department_id' => $data['department_id'] ?? null,
        ]);

        Audit::action('support.canned_response.created')
            ->by($this->actor->model())
            ->on($reply)
            ->write();

        return back()->with('status', __('support.replies.saved'));
    }

    public function update(Request $request, CannedResponse $reply): RedirectResponse
    {
        $this->authorizeFor('support.tickets.manage');

        $data = $this->validated($request);

        $reply->update([
            'name' => (string) $data['name'],
            'body' => (string) $data['body'],
            'department_id' => $data['department_id'] ?? null,
        ]);

        Audit::action('support.canned_response.updated')
            ->by($this->actor->model())
            ->on($reply)
            ->write();

        return back()->with('status', __('support.replies.saved'));
    }

    public function destroy(CannedResponse $reply): RedirectResponse
    {
        $this->authorizeFor('support.tickets.manage');

        Audit::action('support.canned_response.deleted')
            ->by($this->actor->model())
            ->on($reply)
            ->write();

        $reply->delete();

        return back()->with('status', __('support.replies.deleted'));
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        /** @var array<string, mixed> $data */
        $data = $request->validate([
            'name' => ['required', 'string', 'max:191'],
            'body' => ['required', 'string', 'max:20000'],
            // Null means every department. A reply about billing is not
            // wanted in the queue that answers "my site is down".
            'department_id' => ['nullable', 'ulid', 'exists:departments,id'],
        ]);

        return $data;
    }

    private function authorizeFor(string $permission): void
    {
        if (! $this->actor->can($permission)) {
            throw new ForbiddenException(__('support.errors.not_permitted'));
        }
    }
}
