<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Application\Infrastructure\DrainBackend;
use App\Domain\Infrastructure\Exceptions\BackendRefused;
use App\Domain\Infrastructure\LoadBalancing\BackendState;
use App\Http\Controllers\Controller;
use App\Infrastructure\Identity\Models\StaffUser;
use App\Infrastructure\Resources\Models\ResourceEdge;
use App\Infrastructure\Resources\Models\ResourceNode;
use App\Support\Errors\ForbiddenException;
use App\Support\Identity\CurrentActor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * What every load balancer is serving, and the one thing an operator may
 * change about it (§9, §16).
 *
 * **Listeners with their backends underneath**, because the question this
 * screen is opened for is "can I take web-3 out", and that is answered by
 * seeing what else is behind the same listener. A flat list of backends would
 * hide the one fact that matters: how many are left.
 *
 * Draining is behind `infrastructure.drain` **and** the password challenge,
 * with the permission above `auth.recent` on the route — otherwise somebody
 * who may not drain anything is asked to confirm their password and then
 * refused, which is rude and a small oracle. The rule this repository has now
 * learned six times.
 */
final class LoadBalancerController extends Controller
{
    public function __construct(private readonly DrainBackend $backends) {}

    public function index(CurrentActor $actor): Response
    {
        if (! $actor->can('infrastructure.loadbalancers.view')) {
            throw new ForbiddenException(__('automation.errors.not_permitted'));
        }

        $listeners = ResourceNode::query()
            ->where('kind', 'lb_listener')
            ->whereNull('retired_at')
            ->orderBy('label')
            ->get();

        $backends = ResourceNode::query()
            ->where('kind', 'lb_backend')
            ->whereNull('retired_at')
            ->orderBy('label')
            ->get();

        // One query for the whole tree rather than one per listener: a
        // balancer with forty listeners is forty round trips otherwise, and
        // `LazyLoadingTest` exists because that is how this breaks.
        $parents = ResourceEdge::query()
            ->whereIn('to_node_id', $backends->pluck('id'))
            ->whereNull('ended_at')
            ->pluck('from_node_id', 'to_node_id');

        $mayDrain = $actor->can('infrastructure.drain');

        return Inertia::render('Admin/Infrastructure/LoadBalancers', [
            'listeners' => $listeners->map(fn (ResourceNode $listener): array => [
                'id' => $listener->id,
                'name' => $listener->label,
                'address' => $listener->attributes['address'] ?? null,
                'port' => $listener->attributes['port'] ?? null,
                'protocol' => $listener->attributes['protocol'] ?? null,
                'backends' => $backends
                    ->filter(static fn (ResourceNode $backend): bool => ($parents[$backend->id] ?? null) === $listener->id)
                    ->map($this->backend(...))
                    ->values()
                    ->all(),
            ])->values()->all(),
            'can' => ['drain' => $mayDrain],
        ]);
    }

    public function drain(Request $request, CurrentActor $actor, ResourceNode $node): RedirectResponse
    {
        return $this->act($request, $actor, $node, draining: true);
    }

    public function undrain(Request $request, CurrentActor $actor, ResourceNode $node): RedirectResponse
    {
        return $this->act($request, $actor, $node, draining: false);
    }

    private function act(Request $request, CurrentActor $actor, ResourceNode $node, bool $draining): RedirectResponse
    {
        if ($node->kind !== 'lb_backend') {
            // A node of another kind reached by id. 404 rather than 403: a
            // refusal would confirm the row exists.
            abort(404);
        }

        $data = $request->validate([
            // A reason the endpoint discards is a sentence nobody reads, so
            // it is required and it is stored on the audit row.
            'reason' => ['required', 'string', 'max:500'],
        ]);

        $staff = $actor->model();

        if (! $staff instanceof StaffUser) {
            throw new ForbiddenException(__('automation.errors.not_permitted'));
        }

        try {
            $backend = $draining
                ? $this->backends->drain($node, $staff, $data['reason'])
                : $this->backends->undrain($node, $staff, $data['reason']);
        } catch (BackendRefused $refused) {
            // Every refusal reaches the form as a sentence. Anything else
            // still reaches the handler, because anything else is a bug —
            // the rule the Modules screen learned when every refusal on it
            // was a 500 page.
            return back()->withErrors(['reason' => $refused->getMessage()]);
        }

        return back()->with('status', __('infrastructure.loadbalancing.done', [
            'state' => (string) __($backend->state->labelKey()),
        ]));
    }

    /**
     * @return array<string, mixed>
     */
    private function backend(ResourceNode $node): array
    {
        $state = BackendState::tryFrom((string) ($node->attributes['state'] ?? '')) ?? BackendState::Unknown;

        return [
            'id' => $node->id,
            'name' => $node->label,
            'address' => $node->attributes['address'] ?? null,
            'port' => $node->attributes['port'] ?? null,
            'weight' => $node->attributes['weight'] ?? null,
            // Two fields, as everything that crosses to the browser is: the
            // state for the tone and the label for the word.
            'state' => $state->value,
            'stateLabel' => (string) __($state->labelKey()),
            'stateTone' => $state->tone(),
            'serving' => $state->isServing(),
        ];
    }
}
