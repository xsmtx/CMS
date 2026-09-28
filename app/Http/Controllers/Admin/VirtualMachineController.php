<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Application\Infrastructure\ChangeMachinePower;
use App\Domain\Infrastructure\Exceptions\PowerRefused;
use App\Domain\Infrastructure\Virtualisation\MachineState;
use App\Domain\Infrastructure\Virtualisation\PowerAction;
use App\Http\Controllers\Controller;
use App\Infrastructure\Identity\Models\StaffUser;
use App\Infrastructure\Resources\Models\ResourceEdge;
use App\Infrastructure\Resources\Models\ResourceNode;
use App\Support\Errors\ForbiddenException;
use App\Support\Identity\CurrentActor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Every host and the machines on it, and the most consequential button in the
 * product (§10).
 *
 * **Hosts with their machines underneath**, because "what goes down if I
 * reboot hv-3" is the question this screen exists for, and a flat list of
 * machines answers it only by counting. A host that answered about no
 * machines still appears: an empty host is a fact, and leaving it out would
 * read as a host that had gone.
 *
 * Power is behind `infrastructure.power` **and** the password challenge, with
 * the permission above `auth.recent` on the route.
 */
final class VirtualMachineController extends Controller
{
    public function __construct(private readonly ChangeMachinePower $power) {}

    public function index(CurrentActor $actor): Response
    {
        if (! $actor->can('infrastructure.machines.view')) {
            throw new ForbiddenException(__('automation.errors.not_permitted'));
        }

        $hosts = ResourceNode::query()
            ->where('kind', 'hypervisor_host')
            ->whereNull('retired_at')
            ->orderBy('label')
            ->get();

        $machines = ResourceNode::query()
            ->where('kind', 'virtual_machine')
            ->whereNull('retired_at')
            ->orderBy('label')
            ->get();

        // One query for the whole tree rather than one per host, which is the
        // shape `LazyLoadingTest` exists to keep honest.
        $parents = ResourceEdge::query()
            ->whereIn('to_node_id', $machines->pluck('id'))
            ->whereNull('ended_at')
            ->pluck('from_node_id', 'to_node_id');

        return Inertia::render('Admin/Infrastructure/VirtualMachines', [
            'hosts' => $hosts->map(fn (ResourceNode $host): array => [
                'id' => $host->id,
                'name' => $host->label,
                'online' => $host->attributes['online'] ?? null,
                'cluster' => $host->attributes['cluster'] ?? null,
                'machines' => $machines
                    ->filter(static fn (ResourceNode $m): bool => ($parents[$m->id] ?? null) === $host->id)
                    ->map($this->machine(...))
                    ->values()
                    ->all(),
            ])->values()->all(),
            // A hypervisor that answers about machines and not about hosts is
            // a real configuration, and those machines must not vanish.
            'unplaced' => $machines
                ->filter(static fn (ResourceNode $m): bool => ! isset($parents[$m->id]))
                ->map($this->machine(...))
                ->values()
                ->all(),
            'can' => ['power' => $actor->can('infrastructure.power')],
            'actions' => array_map(
                static fn (PowerAction $action): array => [
                    'value' => $action->value,
                    'label' => (string) __($action->labelKey()),
                    'abrupt' => $action->isAbrupt(),
                    'stopsService' => $action->stopsService(),
                ],
                PowerAction::cases(),
            ),
        ]);
    }

    public function power(Request $request, CurrentActor $actor, ResourceNode $node): RedirectResponse
    {
        if ($node->kind !== 'virtual_machine') {
            // A node of another kind reached by id. 404, never 403: a refusal
            // would confirm the row exists.
            abort(404);
        }

        $data = $request->validate([
            'action' => ['required', Rule::enum(PowerAction::class)],
            'reason' => ['required', 'string', 'max:500'],
            // What the operator typed to prove they mean this machine. Checked
            // on the server as well as in the dialog, because a dialog is a
            // convenience and this is the guard.
            'confirm' => ['required', 'string'],
        ]);

        if (trim($data['confirm']) !== $node->label) {
            return back()->withErrors(['confirm' => __('infrastructure.virtualisation.confirm_mismatch')]);
        }

        $staff = $actor->model();

        if (! $staff instanceof StaffUser) {
            throw new ForbiddenException(__('automation.errors.not_permitted'));
        }

        try {
            $machine = $this->power->handle(
                $node,
                PowerAction::from($data['action']),
                $staff,
                $data['reason'],
            );
        } catch (PowerRefused $refused) {
            // Every refusal reaches the form as a sentence; anything else
            // still reaches the handler, because anything else is a bug.
            return back()->withErrors(['reason' => $refused->getMessage()]);
        }

        return back()->with('status', __('infrastructure.virtualisation.done', [
            'state' => (string) __($machine->state->labelKey()),
        ]));
    }

    /**
     * @return array<string, mixed>
     */
    private function machine(ResourceNode $node): array
    {
        $state = MachineState::tryFrom((string) ($node->attributes['state'] ?? '')) ?? MachineState::Unknown;

        return [
            'id' => $node->id,
            'name' => $node->label,
            'kind' => $node->attributes['kind'] ?? null,
            'vcpus' => $node->attributes['vcpus'] ?? null,
            'state' => $state->value,
            'stateLabel' => (string) __($state->labelKey()),
            'stateTone' => $state->tone(),
            'running' => $state === MachineState::Running,
        ];
    }
}
