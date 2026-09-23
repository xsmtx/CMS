<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Application\Provisioning\OpenServerSession;
use App\Domain\Licensing\Contracts\Entitlements;
use App\Domain\Licensing\Feature;
use App\Domain\Provisioning\Contracts\OpensPanelSessions;
use App\Domain\Provisioning\PanelSession;
use App\Http\Controllers\Controller;
use App\Infrastructure\Provisioning\Models\Server;
use App\Infrastructure\Provisioning\ModuleRegistry;
use App\Support\Branding\CurrentBrand;
use App\Support\Identity\CurrentActor;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Connect: what this installation is joined to, and a way in.
 *
 * Named after the brand rather than after us, because on a white-label
 * installation the operator works for the reseller and the page is theirs.
 *
 * Two things live here. What the licence says this installation may do —
 * which is a seam with a dull default (ADR 0022, ADR 0036), not a call to
 * anybody's server. And the servers whose credentials this platform already
 * holds, with a way into each one's panel that does not involve a password.
 *
 * **Passwordless is the point, and it is stricter than a password, not
 * looser.** The platform asks the panel to issue a short-lived session with
 * the API token it already has for provisioning. The token never reaches a
 * browser, the session expires, and every one is audited against the
 * operator who asked. Revealing a stored root password would be none of
 * those things, and this platform does not do it.
 *
 * Super administrators only, like everything behind the Apps door.
 */
final class ConnectController extends Controller
{
    public function __construct(
        private readonly CurrentActor $actor,
        private readonly ModuleRegistry $modules,
    ) {}

    public function index(Entitlements $entitlements, CurrentBrand $brand): Response
    {
        AppsController::assertSuperAdminFor($this->actor);

        return Inertia::render('Admin/Apps/Connect', [
            'brand' => $brand->current()->name,
            'platform' => [
                'version' => (string) config('platform.version', '1.0.0'),
                // What this installation is allowed to do. A seam, and a
                // default that allows everything: a gate whose default is
                // deny turns an unreachable licence API into an outage.
                'entitlements' => array_values(array_map(
                    static fn (Feature $feature): array => [
                        'key' => $feature->value,
                        'allowed' => $entitlements->allows($feature->value),
                    ],
                    Feature::cases(),
                )),
            ],
            'servers' => array_values(Server::query()
                ->with('group')
                ->orderBy('name')
                ->get()
                ->map(fn (Server $server): array => [
                    'id' => $server->id,
                    'name' => $server->name,
                    'hostname' => $server->hostname,
                    'ipAddress' => $server->ip_address,
                    'group' => $server->group?->name,
                    'module' => $server->module,
                    'status' => $server->status->value,
                    // Whether this one can let somebody in without a
                    // password. Asked of the module rather than assumed, so
                    // a server on a panel that cannot do it says so instead
                    // of offering a button that fails.
                    'canOpenSession' => $this->canOpenSession($server),
                    'hasSecret' => $server->secret !== null,
                ])
                ->all()),
        ]);
    }

    /**
     * Ask the panel for a session and send the operator to it.
     *
     * `Inertia::location` rather than a normal redirect: the destination is
     * somebody else's application, and the single-page shell has to leave
     * rather than try to render it.
     */
    public function openSession(string $server, OpenServerSession $sessions): RedirectResponse
    {
        AppsController::assertSuperAdminFor($this->actor);

        $record = Server::query()->whereKey($server)->first();

        if (! $record instanceof Server) {
            abort(404);
        }

        $session = $sessions->handle($record, $this->actor->model());

        if (! $session instanceof PanelSession) {
            return back()->with('error', __('provisioning.servers.no_session'));
        }

        // Never stored, never logged, never rendered — handed straight to
        // the redirect and forgotten.
        return redirect()->away($session->url);
    }

    private function canOpenSession(Server $server): bool
    {
        if ($server->module === null || $server->secret === null) {
            return false;
        }

        return $this->modules->find($server->module) instanceof OpensPanelSessions;
    }
}
