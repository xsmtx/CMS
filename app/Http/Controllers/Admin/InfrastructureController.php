<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Provisioning\Contracts\ProvisioningModule;
use App\Domain\Provisioning\PlacementStrategy;
use App\Domain\Provisioning\ServerStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Provisioning\ServerGroupRequest;
use App\Http\Requests\Provisioning\ServerRequest;
use App\Infrastructure\Provisioning\Jobs\CheckServerHealth;
use App\Infrastructure\Provisioning\Models\Server;
use App\Infrastructure\Provisioning\Models\ServerGroup;
use App\Infrastructure\Provisioning\ModuleRegistry;
use App\Support\Audit\Facades\Audit;
use App\Support\Errors\ForbiddenException;
use App\Support\Identity\CurrentActor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The fleet.
 *
 * Holds credentials for somebody's production control panels, which is why
 * `infrastructure.manage` is a high-risk permission and why the token is
 * never sent back to the browser — not even masked. An operator who needs
 * to change it types a new one.
 */
final class InfrastructureController extends Controller
{
    public function __construct(
        private readonly CurrentActor $actor,
        private readonly ModuleRegistry $modules,
    ) {}

    public function index(): Response
    {
        $this->authorizeView();

        $servers = Server::query()
            ->with('group')
            ->withCount(['occupyingServices as services_count'])
            ->orderBy('name')
            ->get();

        return Inertia::render('Admin/Infrastructure/Index', [
            'groups' => ServerGroup::query()
                ->withCount('servers')
                ->orderBy('name')
                ->get()
                ->map(fn (ServerGroup $group): array => [
                    'id' => $group->id,
                    'name' => $group->name,
                    'strategy' => $group->placement_strategy->value,
                    'strategyLabel' => (string) __($group->placement_strategy->labelKey()),
                    'region' => $group->region,
                    'servers' => $group->servers_count,
                    'notes' => $group->notes,
                ])
                ->values()
                ->all(),
            'servers' => $servers
                ->map(fn (Server $server): array => [
                    'id' => $server->id,
                    'name' => $server->name,
                    'group' => $server->group?->name,
                    'groupId' => $server->server_group_id,
                    'module' => $server->module,
                    'hostname' => $server->hostname,
                    'ipAddress' => $server->ip_address,
                    'port' => $server->port,
                    'secure' => $server->secure,
                    'username' => $server->username,
                    'hasSecret' => $server->secret !== null && $server->secret !== '',
                    'status' => $server->status->value,
                    'statusLabel' => (string) __($server->status->labelKey()),
                    'region' => $server->region,
                    'maxServices' => $server->max_services,
                    'weight' => $server->weight,
                    'nameservers' => $server->nameservers,
                    'services' => $server->services_count,
                    'health' => $server->health,
                    'healthLabel' => (string) __('provisioning.health.'.$server->health),
                    'healthMessage' => $server->health_message,
                    'healthCheckedAt' => $server->health_checked_at?->toIso8601String(),
                ])
                ->values()
                ->all(),
            'options' => [
                'modules' => array_map(
                    static fn (ProvisioningModule $module): array => [
                        'value' => $module->key(),
                        'label' => $module->key(),
                        'needsServer' => $module->capabilities()->needsServer,
                    ],
                    $this->modules->all(),
                ),
                'strategies' => array_values(array_map(
                    static fn (PlacementStrategy $strategy): array => [
                        'value' => $strategy->value,
                        'label' => (string) __($strategy->labelKey()),
                    ],
                    PlacementStrategy::cases(),
                )),
                'statuses' => array_values(array_map(
                    static fn (ServerStatus $status): array => [
                        'value' => $status->value,
                        'label' => (string) __($status->labelKey()),
                    ],
                    ServerStatus::cases(),
                )),
            ],
            'can' => ['manage' => $this->actor->can('infrastructure.manage')],
        ]);
    }

    public function storeGroup(ServerGroupRequest $request): RedirectResponse
    {
        $this->authorizeManage();

        $name = $request->string('name')->toString();

        $group = ServerGroup::query()->create([
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(4)),
            'placement_strategy' => $request->string('placement_strategy')->toString(),
            'region' => $request->input('region'),
            'notes' => $request->input('notes'),
        ]);

        Audit::action('provisioning.server_group.created')->by($this->actor->model())->on($group)->write();

        return back()->with('status', __('provisioning.servers.group_saved'));
    }

    public function updateGroup(ServerGroupRequest $request, ServerGroup $group): RedirectResponse
    {
        $this->authorizeManage();

        $before = $group->only(['name', 'placement_strategy', 'region']);

        $group->update([
            'name' => $request->string('name')->toString(),
            'placement_strategy' => $request->string('placement_strategy')->toString(),
            'region' => $request->input('region'),
            'notes' => $request->input('notes'),
        ]);

        Audit::action('provisioning.server_group.updated')
            ->by($this->actor->model())
            ->on($group)
            ->changed($before, $group->only(['name', 'placement_strategy', 'region']))
            ->write();

        return back()->with('status', __('provisioning.servers.group_saved'));
    }

    public function destroyGroup(ServerGroup $group): RedirectResponse
    {
        $this->authorizeManage();

        $group->delete();

        Audit::action('provisioning.server_group.deleted')->by($this->actor->model())->on($group)->write();

        return back()->with('status', __('provisioning.servers.group_deleted'));
    }

    public function storeServer(ServerRequest $request): RedirectResponse
    {
        $this->authorizeManage();

        $server = Server::query()->create($this->attributes($request));

        Audit::action('provisioning.server.created')
            ->by($this->actor->model())
            ->on($server)
            // Never the token, even in an audit row an operator can read.
            ->withMetadata(['hostname' => $server->hostname, 'module' => $server->module])
            ->write();

        return back()->with('status', __('provisioning.servers.saved'));
    }

    public function updateServer(ServerRequest $request, Server $server): RedirectResponse
    {
        $this->authorizeManage();

        $before = $server->only(['name', 'hostname', 'status', 'max_services']);
        $attributes = $this->attributes($request);

        // An empty field keeps the stored token rather than erasing it.
        if (($attributes['secret'] ?? null) === null || $attributes['secret'] === '') {
            unset($attributes['secret']);
        }

        $server->update($attributes);

        Audit::action('provisioning.server.updated')
            ->by($this->actor->model())
            ->on($server)
            ->changed($before, $server->only(['name', 'hostname', 'status', 'max_services']))
            ->write();

        return back()->with('status', __('provisioning.servers.saved'));
    }

    public function destroyServer(Server $server): RedirectResponse
    {
        $this->authorizeManage();

        if ($server->occupyingServices()->exists()) {
            // Deleting it would orphan running accounts nobody could find
            // again.
            return back()->with('error', __('provisioning.servers.in_use'));
        }

        $server->delete();

        Audit::action('provisioning.server.deleted')->by($this->actor->model())->on($server)->write();

        return back()->with('status', __('provisioning.servers.deleted'));
    }

    public function test(Server $server): RedirectResponse
    {
        $this->authorizeManage();

        dispatch(new CheckServerHealth($server->id));

        return back()->with('status', __('provisioning.services.queued'));
    }

    /**
     * @return array<string, mixed>
     */
    private function attributes(ServerRequest $request): array
    {
        return [
            'name' => $request->string('name')->toString(),
            'server_group_id' => $request->input('server_group_id'),
            'module' => $request->string('module')->toString(),
            'hostname' => $request->string('hostname')->toString(),
            'ip_address' => $request->input('ip_address'),
            'port' => $request->integer('port'),
            'secure' => $request->boolean('secure'),
            'username' => $request->string('username')->toString(),
            'secret' => $request->input('secret'),
            'status' => $request->string('status')->toString(),
            'region' => $request->input('region'),
            'max_services' => $request->integer('max_services'),
            'weight' => $request->integer('weight'),
            'nameservers' => $request->input('nameservers'),
        ];
    }

    private function authorizeView(): void
    {
        if (! $this->actor->can('infrastructure.view')) {
            throw new ForbiddenException(__('provisioning.services.not_permitted'));
        }
    }

    private function authorizeManage(): void
    {
        if (! $this->actor->can('infrastructure.manage')) {
            throw new ForbiddenException(__('provisioning.services.not_permitted'));
        }
    }
}
