<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Application\Reliability\MaintenanceWindows;
use App\Domain\Reliability\Exceptions\MaintenanceRefused;
use App\Http\Controllers\Controller;
use App\Http\Requests\Reliability\MaintenanceWindowRequest;
use App\Infrastructure\Identity\Models\StaffUser;
use App\Infrastructure\Reliability\Models\Alert;
use App\Infrastructure\Reliability\Models\MaintenanceWindow;
use App\Support\Errors\ForbiddenException;
use App\Support\Identity\CurrentActor;
use App\Support\Organizations\OrganizationContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Planned work (§16).
 *
 * **What is running, what is coming, and what already ran** — in that order,
 * because an operator opening this screen at two in the morning is asking the
 * first question and a planner opening it on Monday is asking the second. The
 * third is the one somebody asks with an incident open beside it: "was
 * anything scheduled when this broke".
 *
 * A window's state is never stored, so there is nothing here that moves one
 * along. It starts because the clock passed its start, and the only human act
 * after scheduling is calling it off.
 */
final class MaintenanceController extends Controller
{
    public function index(CurrentActor $actor): Response
    {
        $this->refuseUnless($actor, 'reliability.maintenance.manage');

        $now = CarbonImmutable::now();

        $windows = MaintenanceWindow::query()
            ->with('author')
            ->latest('starts_at')
            ->limit(100)
            ->get();

        // Counted per window rather than joined: a window suppresses a
        // handful of alerts, and the figure is the answer to "did this
        // actually hold anything back".
        $suppressed = Alert::query()
            ->whereIn('suppressed_by', $windows->pluck('id')->all())
            ->selectRaw('suppressed_by, COUNT(*) as total')
            ->groupBy('suppressed_by')
            ->get()
            ->mapWithKeys(static fn (Alert $alert): array => [
                (string) $alert->suppressed_by => (int) $alert->getAttribute('total'),
            ]);

        return Inertia::render('Admin/Reliability/Maintenance', [
            'windows' => array_values($windows
                ->map(fn (MaintenanceWindow $window): array => $this->row($window, $now, (int) ($suppressed[$window->id] ?? 0)))
                ->all()),
            'can' => ['manage' => true],
        ]);
    }

    public function store(
        MaintenanceWindowRequest $request,
        CurrentActor $actor,
        MaintenanceWindows $windows,
    ): RedirectResponse {
        $this->refuseUnless($actor, 'reliability.maintenance.manage');

        $data = $request->validated();

        try {
            $windows->schedule(
                organizationId: (string) app(OrganizationContext::class)->id(),
                title: $data['title'],
                startsAt: CarbonImmutable::parse($data['starts_at']),
                endsAt: CarbonImmutable::parse($data['ends_at']),
                body: $data['body'] ?? null,
                nodeKeys: $this->nodeKeys($data['node_keys'] ?? null),
                public: (bool) ($data['is_public'] ?? false),
                actor: $this->staff($actor),
            );
        } catch (MaintenanceRefused $refusal) {
            return back()->withErrors(['ends_at' => $refusal->worded()]);
        }

        return back()->with('status', __('reliability.maintenance.scheduled'));
    }

    public function cancel(
        MaintenanceWindow $window,
        CurrentActor $actor,
        MaintenanceWindows $windows,
    ): RedirectResponse {
        $this->refuseUnless($actor, 'reliability.maintenance.manage');

        try {
            $windows->cancel($window, $this->staff($actor));
        } catch (MaintenanceRefused $refusal) {
            return back()->withErrors(['window' => $refusal->worded()]);
        }

        return back()->with('status', __('reliability.maintenance.cancelled'));
    }

    /**
     * One window, with the word for where it is in its own life.
     *
     * Derived here rather than stored, so a window that started ten seconds
     * ago says so — and one that ended while the tab was open says that
     * instead of what it said when the page was rendered.
     *
     * @return array<string, mixed>
     */
    private function row(MaintenanceWindow $window, CarbonImmutable $now, int $suppressed): array
    {
        $state = match (true) {
            $window->isCancelled() => 'cancelled',
            $window->isActive($now) => 'active',
            $window->hasEnded($now) => 'ended',
            default => 'scheduled',
        };

        return [
            'id' => $window->id,
            'title' => $window->title,
            'body' => $window->body,
            'state' => $state,
            'stateLabel' => (string) __('reliability.maintenance.states.'.$state),
            // The tone is decided here for the reason every other one is:
            // one mapping, on the server.
            'stateTone' => match ($state) {
                'active' => 'maintenance',
                'scheduled' => 'info',
                'ended' => 'neutral',
                default => 'neutral',
            },
            'startsAt' => $window->starts_at->toIso8601String(),
            'endsAt' => $window->ends_at->toIso8601String(),
            'isPublic' => $window->is_public,
            'nodeKeys' => $window->node_keys,
            'author' => $window->author?->name,
            'suppressed' => $suppressed,
            'canCancel' => ! $window->isCancelled() && ! $window->hasEnded($now),
        ];
    }

    /**
     * The machines a window covers, from a textarea.
     *
     * One per line, because a comma is a legal character in nothing here and
     * an operator pasting a column out of a spreadsheet is the common case.
     * Empty means the whole installation, which is the ordinary window.
     *
     * @return list<string>
     */
    private function nodeKeys(?string $raw): array
    {
        if ($raw === null || trim($raw) === '') {
            return [];
        }

        return array_values(array_filter(array_map(
            trim(...),
            preg_split('/\r\n|\r|\n/', $raw) ?: [],
        ), static fn (string $line): bool => $line !== ''));
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
