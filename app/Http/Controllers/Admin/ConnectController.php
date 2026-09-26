<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Application\Network\AccessGrants;
use App\Application\Provisioning\OpenServerSession;
use App\Domain\Licensing\Contracts\Entitlements;
use App\Domain\Licensing\Feature;
use App\Domain\Network\Exceptions\GrantRefused;
use App\Domain\Network\GrantableCapability;
use App\Domain\Provisioning\Contracts\OpensPanelSessions;
use App\Domain\Provisioning\PanelSession;
use App\Http\Controllers\Controller;
use App\Http\Requests\Network\GrantAccessRequest;
use App\Infrastructure\Identity\Models\StaffUser;
use App\Infrastructure\Network\Models\AccessGrant;
use App\Infrastructure\Provisioning\Models\Server;
use App\Infrastructure\Provisioning\ModuleRegistry;
use App\Support\Branding\CurrentBrand;
use App\Support\Errors\ForbiddenException;
use App\Support\Identity\CurrentActor;
use Carbon\CarbonImmutable;
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
 * **It is a permission, not the owner-only gate**, and that is the whole
 * point of the screen. A support agent fixing somebody's mailbox has to get
 * into the panel; the alternative to letting them is emailing them a root
 * password or an API key, which is exactly what this exists to stop. So
 * `infrastructure.connect` is granted to the people who need it, the
 * credential stays on this server, and the audit record says who went where.
 *
 * The list is bounded like every other query here, so a reseller's operator
 * sees the servers their own organization owns and no others.
 */
final class ConnectController extends Controller
{
    public function __construct(
        private readonly CurrentActor $actor,
        private readonly ModuleRegistry $modules,
        private readonly AccessGrants $grants,
    ) {}

    public function index(Entitlements $entitlements, CurrentBrand $brand): Response
    {
        $this->authorizeConnect();

        return Inertia::render('Admin/Apps/Connect', [
            // `brandName`, not `brand`: the shell shares a prop of that name
            // and a page's own wins, which left the footer with no company on
            // it and `useBranding()` holding a string. Found once on the
            // settings screen already.
            'brandName' => $brand->current()->name,
            'platform' => [
                'version' => (string) config('platform.version', '1.0.0'),
                // What this installation is allowed to do. A seam, and a
                // default that allows everything: a gate whose default is
                // deny turns an unreachable licence API into an outage.
                'entitlements' => array_values(array_map(
                    static fn (Feature $feature): array => [
                        'key' => $feature->value,
                        // The wording, not the slug. A feature's value has dots
                        // in it, so it is not a path a translator can walk —
                        // `labelKey()` is the one place that knows to underscore
                        // it, and the screen printed the raw slug without it.
                        'label' => (string) __($feature->labelKey()),
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
                    // Two fields, because a status crossing to the browser is
                    // a tone and a word: the raw value picks the mark, the
                    // translated label is what an operator reads.
                    'statusLabel' => (string) __($server->status->labelKey()),
                    // Whether this one can let somebody in without a
                    // password. Asked of the module rather than assumed, so
                    // a server on a panel that cannot do it says so instead
                    // of offering a button that fails.
                    'canOpenSession' => $this->canOpenSession($server),
                    'hasSecret' => $server->secret !== null,
                ])
                ->all()),

            /*
             * Just-in-time access (§17), on the screen it extends.
             *
             * Connect is already the answer to "somebody needs into a panel
             * without being handed a root password"; a grant is the answer to
             * "and they do not hold the permission". Putting the list
             * anywhere else would be a second screen about the same door.
             */
            'grants' => array_values(AccessGrant::query()
                ->live()
                ->with(['holder', 'granter'])
                ->orderBy('expires_at')
                ->get()
                ->map(static fn (AccessGrant $grant): array => [
                    'id' => $grant->id,
                    'holder' => $grant->holder?->name,
                    'granter' => $grant->granter?->name,
                    'capability' => $grant->capability->value,
                    'capabilityLabel' => (string) __($grant->capability->labelKey()),
                    'reason' => $grant->reason,
                    'ticket' => $grant->ticket,
                    'expiresAt' => $grant->expires_at->toIso8601String(),
                ])
                ->all()),

            'grantable' => array_values(array_map(
                static fn (GrantableCapability $capability): array => [
                    'value' => $capability->value,
                    'label' => (string) __($capability->labelKey()),
                ],
                GrantableCapability::cases(),
            )),

            'staff' => $this->actor->can('network.access.grant')
                ? array_values(StaffUser::query()
                    ->where('id', '!=', $this->actor->model()?->getKey())
                    ->orderBy('name')
                    ->get(['id', 'name'])
                    ->map(static fn (StaffUser $user): array => [
                        'value' => $user->id,
                        // Nobody grants themselves anything, so the person
                        // asking is not on the list. Refused on the server
                        // too — a select is a convenience, never a rule.
                        'label' => $user->name,
                    ])
                    ->all())
                : [],

            'can' => [
                'grant' => $this->actor->can('network.access.grant'),
            ],
        ]);
    }

    /**
     * Give somebody one more thing than usual, until a time.
     */
    public function grant(GrantAccessRequest $request, AccessGrants $grants): RedirectResponse
    {
        $this->refuseUnlessMayGrant();

        $data = $request->validated();

        $holder = StaffUser::query()->whereKey($data['staff'])->firstOrFail();
        $granter = $this->actor->model();

        if (! $granter instanceof StaffUser) {
            throw new ForbiddenException(__('provisioning.servers.connect_not_permitted'));
        }

        try {
            $grants->grant(
                holder: $holder,
                capability: GrantableCapability::from($data['capability']),
                granter: $granter,
                reason: $data['reason'],
                expiresAt: CarbonImmutable::now()->addMinutes((int) $data['minutes']),
                ticket: $data['ticket'] ?? null,
            );
        } catch (GrantRefused $refusal) {
            return back()->withErrors(['staff' => $refusal->getMessage()])->withInput();
        }

        return back()->with('status', __('network.access.flash.granted'));
    }

    /**
     * End one early.
     */
    public function revokeGrant(string $grant, AccessGrants $grants): RedirectResponse
    {
        $this->refuseUnlessMayGrant();

        $record = AccessGrant::query()->whereKey($grant)->first();

        if (! $record instanceof AccessGrant) {
            abort(404);
        }

        $actor = $this->actor->model();

        try {
            $grants->revoke(
                $record,
                $actor instanceof StaffUser ? $actor : null,
                'revoked by an operator',
            );
        } catch (GrantRefused $refusal) {
            return back()->withErrors(['grant' => $refusal->getMessage()]);
        }

        return back()->with('status', __('network.access.flash.revoked'));
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
        $this->authorizeConnect();

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

    private function refuseUnlessMayGrant(): void
    {
        if (! $this->actor->can('network.access.grant')) {
            throw new ForbiddenException(__('automation.errors.not_permitted'));
        }
    }

    /**
     * Asked on both endpoints rather than once on the group.
     *
     * The list and the way in are the same decision, and a route added later
     * without the middleware would otherwise be an open one — two cheap checks
     * are worth less than one forgotten.
     */
    /**
     * The permission, **or** a grant that has not run out.
     *
     * §17's just-in-time access, and this is the one place it is read. The
     * order matters only for cost: somebody who holds the permission never
     * touches the table.
     *
     * A grant only ever adds, so asking this everywhere is safe — and the
     * question is put to the grant's own timestamps rather than to a state
     * column, so a scheduler that was down for three hours leaves nobody
     * holding access they should not have.
     */
    private function authorizeConnect(): void
    {
        if ($this->actor->can('infrastructure.connect')) {
            return;
        }

        $staff = $this->actor->model();

        if ($staff instanceof StaffUser
            && $this->grants->allows($staff, GrantableCapability::Connect)) {
            return;
        }

        throw new ForbiddenException(__('provisioning.servers.connect_not_permitted'));
    }

    private function canOpenSession(Server $server): bool
    {
        if ($server->module === null || $server->secret === null) {
            return false;
        }

        return $this->modules->find($server->module) instanceof OpensPanelSessions;
    }
}
