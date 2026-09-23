<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Application\Automation\RecordedRun;
use App\Application\Automation\TaskRegistry;
use App\Application\Health\MaintenanceMode;
use App\Domain\Automation\AutomationTask;
use App\Domain\Automation\DunningAction;
use App\Domain\Notifications\NotificationEvent;
use App\Http\Controllers\Controller;
use App\Http\Requests\Automation\DunningStepRequest;
use App\Http\Requests\Automation\MaintenanceRequest;
use App\Infrastructure\Automation\Models\AutomationRunRecord;
use App\Infrastructure\Automation\Models\DunningStep;
use App\Support\Audit\Facades\Audit;
use App\Support\Errors\ForbiddenException;
use App\Support\Identity\CurrentActor;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * What this platform does on its own.
 *
 * The screen shows every task whether or not it has ever run, with its last
 * run beside it. A list of past runs alone cannot answer "is the renewal
 * sweep working", because the answer there is an absence.
 *
 * "Run now" runs the task in the request rather than queueing it, and that
 * is deliberate: an operator pressing it is trying to watch what happens.
 * A queued run would return immediately and teach them nothing.
 */
final class AutomationController extends Controller
{
    public function __construct(
        private readonly CurrentActor $actor,
        private readonly TaskRegistry $tasks,
        private readonly RecordedRun $runner,
        private readonly MaintenanceMode $maintenance,
    ) {}

    public function index(): Response
    {
        $this->authorizeFor('automation.view');

        $latest = AutomationRunRecord::query()
            ->latest('started_at')
            ->limit(200)
            ->get();

        return Inertia::render('Admin/Automation/Index', [
            'tasks' => array_map(
                function (AutomationTask $task) use ($latest): array {
                    $last = $latest->firstWhere('task', $task);

                    return [
                        'value' => $task->value,
                        'label' => (string) __($task->labelKey()),
                        'description' => (string) __($task->descriptionKey()),
                        'intervalMinutes' => $task->intervalMinutes(),
                        'command' => $task->command(),
                        'lastRun' => $last === null ? null : $this->row($last),
                    ];
                },
                AutomationTask::cases(),
            ),
            'runs' => $latest->take(50)->map(fn (AutomationRunRecord $run): array => $this->row($run))
                ->values()
                ->all(),
            'can' => ['run' => $this->actor->can('automation.run')],
        ]);
    }

    public function run(string $task): RedirectResponse
    {
        $this->authorizeFor('automation.run');

        $resolved = AutomationTask::tryFrom($task);

        if (! $resolved instanceof AutomationTask) {
            return back()->withErrors(['task' => __('automation.errors.unknown_task')]);
        }

        // In the request, not on the queue. Somebody pressed this to watch.
        $record = $this->runner->handle(
            $resolved,
            $this->tasks->resolve($resolved),
            $this->actor->model(),
        );

        Audit::action('automation.task.run')
            ->by($this->actor->model())
            ->on($record)
            ->withMetadata(['changed' => $record->changed, 'failed' => $record->failed])
            ->write();

        return back()->with('status', __('automation.runs.queued'));
    }

    public function dunning(): Response
    {
        $this->authorizeFor('automation.view');

        return Inertia::render('Admin/Automation/Dunning', [
            'steps' => DunningStep::query()
                ->orderBy('position')
                ->orderBy('offset_days')
                ->get()
                ->map(fn (DunningStep $step): array => [
                    'id' => $step->id,
                    'offsetDays' => $step->offset_days,
                    'action' => $step->action->value,
                    'actionLabel' => (string) __($step->action->labelKey()),
                    'event' => $step->event,
                    'isActive' => $step->is_active,
                    'when' => $this->whenLabel($step->offset_days),
                ])
                ->values()
                ->all(),
            'actions' => array_map(
                static fn (DunningAction $action): array => [
                    'value' => $action->value,
                    'label' => (string) __($action->labelKey()),
                    'needsEvent' => $action->needsEvent(),
                ],
                DunningAction::cases(),
            ),
            'events' => array_map(
                static fn (NotificationEvent $event): array => [
                    'value' => $event->value,
                    'label' => (string) __($event->labelKey()),
                ],
                NotificationEvent::cases(),
            ),
            'can' => ['manage' => $this->actor->can('automation.dunning.manage')],
        ]);
    }

    public function storeStep(DunningStepRequest $request): RedirectResponse
    {
        $this->authorizeFor('automation.dunning.manage');

        $step = DunningStep::query()->create([
            'offset_days' => $request->integer('offset_days'),
            'action' => $request->string('action')->toString(),
            'event' => $request->input('event'),
            'is_active' => true,
            // Ordered by the offset by default, which is the order they
            // will actually happen in.
            'position' => $request->integer('offset_days') + 1000,
        ]);

        Audit::action('automation.dunning.step_added')
            ->by($this->actor->model())
            ->on($step)
            ->write();

        return back()->with('status', __('automation.dunning.saved'));
    }

    public function destroyStep(DunningStep $step): RedirectResponse
    {
        $this->authorizeFor('automation.dunning.manage');

        Audit::action('automation.dunning.step_removed')
            ->by($this->actor->model())
            ->on($step)
            ->write();

        $step->delete();

        return back()->with('status', __('automation.dunning.removed'));
    }

    public function maintenance(MaintenanceRequest $request): RedirectResponse
    {
        $this->authorizeFor('platform.maintenance.manage');

        if (! $request->boolean('enabled')) {
            $this->maintenance->disable();

            Audit::action('platform.maintenance.disabled')->by($this->actor->model())->write();

            return back()->with('status', __('automation.maintenance.disabled'));
        }

        $until = $request->input('until');

        $this->maintenance->enable(
            $request->string('message')->toString() ?: (string) __('automation.maintenance.default_message'),
            is_string($until) && $until !== '' ? CarbonImmutable::parse($until) : null,
        );

        Audit::action('platform.maintenance.enabled')
            ->by($this->actor->model())
            ->withMetadata(['until' => $until])
            ->write();

        return back()->with('status', __('automation.maintenance.enabled'));
    }

    /**
     * @return array<string, mixed>
     */
    private function row(AutomationRunRecord $run): array
    {
        return [
            'id' => $run->id,
            'task' => $run->task->value,
            'taskLabel' => (string) __($run->task->labelKey()),
            'status' => $run->status->value,
            'statusLabel' => (string) __($run->status->labelKey()),
            'startedAt' => $run->started_at->toIso8601String(),
            'finishedAt' => $run->finished_at?->toIso8601String(),
            'durationSeconds' => $run->duration(),
            'examined' => $run->examined,
            'changed' => $run->changed,
            'skipped' => $run->skipped,
            'failed' => $run->failed,
            'error' => $run->error,
        ];
    }

    private function whenLabel(int $offsetDays): string
    {
        if ($offsetDays === 0) {
            return (string) __('automation.dunning.on_due');
        }

        return $offsetDays < 0
            ? (string) __('automation.dunning.before_due', ['days' => abs($offsetDays)])
            : (string) __('automation.dunning.after_due', ['days' => $offsetDays]);
    }

    private function authorizeFor(string $permission): void
    {
        if (! $this->actor->can($permission)) {
            throw new ForbiddenException(__('automation.errors.not_permitted'));
        }
    }
}
