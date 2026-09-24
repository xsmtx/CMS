<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Application\Infrastructure\AdapterRegistry;
use App\Application\Infrastructure\CapabilityNames;
use App\Application\Infrastructure\RegisteredAdapter;
use App\Application\Infrastructure\SetAdapterWrites;
use App\Domain\Health\HealthState;
use App\Domain\Infrastructure\Capability;
use App\Http\Controllers\Controller;
use App\Infrastructure\Organizations\Models\Organization;
use App\Infrastructure\Resources\Models\ResourceAdapter;
use App\Support\Errors\ForbiddenException;
use App\Support\Identity\CurrentActor;
use App\Support\Logging\SecretRedactor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * What this installation can read from, and what it may change.
 *
 * The screen exists to make one decision legible: allowing an adapter to write is
 * the moment this platform stops being a window onto somebody's estate and starts
 * being a control plane over it. So the confirmation names the capabilities
 * individually rather than saying "allow changes", the permission behind it is
 * declared high risk — which brings Phase 17's password challenge with it — and
 * the audit record lists what was allowed.
 *
 * An adapter with nothing to write says so and offers no switch. A toggle that
 * did nothing would teach an operator that the toggle means nothing.
 */
final class ResourceAdapterController extends Controller
{
    public function __construct(
        private readonly CurrentActor $actor,
        private readonly AdapterRegistry $registry,
        private readonly SetAdapterWrites $writes,
        private readonly CapabilityNames $names,
    ) {}

    public function index(): Response
    {
        $this->authorizeFor('infrastructure.adapters.view');

        $organizationId = $this->providerOrganizationId();

        if ($organizationId === null) {
            return Inertia::render('Admin/Resources/Adapters', [
                'adapters' => [],
                'orphaned' => [],
                'can' => ['manage' => false],
            ]);
        }

        return Inertia::render('Admin/Resources/Adapters', [
            'adapters' => array_map(
                $this->row(...),
                $this->registry->all($organizationId),
            ),
            'orphaned' => array_map(
                fn (ResourceAdapter $row): array => [
                    'id' => $row->id,
                    'key' => $row->adapter_key,
                    'name' => $row->name,
                    'vendor' => $row->vendor,
                    'module' => $row->module,
                    'writesEnabled' => $row->writes_enabled,
                ],
                $this->registry->orphaned($organizationId),
            ),
            'can' => ['manage' => $this->actor->can('infrastructure.adapters.manage')],
        ]);
    }

    /**
     * Turn an adapter on or off.
     *
     * Deliberately **not** behind the recent-password challenge: switching an
     * adapter off is what an operator does when something is going wrong, and a
     * guard in front of it would be a guard that made an outage longer. Allowing
     * it to write is the endpoint below, which is guarded.
     */
    public function update(Request $request, ResourceAdapter $adapter): RedirectResponse
    {
        $this->authorizeFor('infrastructure.adapters.manage');

        $data = $request->validate(['enabled' => ['required', 'boolean']]);

        $this->writes->setEnabled($adapter, (bool) $data['enabled'], $this->actor->model());

        return back()->with('status', __('infrastructure.adapters.title'));
    }

    /**
     * Allow or revoke writing.
     *
     * The route asks for a password again. What is being agreed to is named
     * capability by capability on the screen and again in the audit record,
     * because "allow changes" is not something anybody can meaningfully consent
     * to.
     */
    public function writes(Request $request, ResourceAdapter $adapter): RedirectResponse
    {
        $this->authorizeFor('infrastructure.adapters.manage');

        $data = $request->validate([
            'writes_enabled' => ['required', 'boolean'],
            'reason' => ['nullable', 'string', 'max:191'],
        ]);

        $this->writes->handle(
            $adapter,
            (bool) $data['writes_enabled'],
            $this->actor->model(),
            // `??`, not `$data['reason']`: `validate()` returns only the keys
            // that were submitted, so a field the form left empty is absent
            // rather than null.
            $data['reason'] ?? null,
        );

        return back()->with('status', __('infrastructure.adapters.title'));
    }

    /**
     * Ask the adapter how it is, now, because somebody is looking at it.
     *
     * A button rather than a sweep: there is no background health run for adapters
     * in this phase, because a sweep that polled twenty devices every five minutes
     * before anybody had configured a timeout would be this platform's first
     * denial-of-service against its own operator. Phase B adds the sweep, with the
     * rate limits it declares.
     */
    public function check(ResourceAdapter $adapter, SecretRedactor $redactor): RedirectResponse
    {
        $this->authorizeFor('infrastructure.adapters.view');

        $registered = $this->registry->find($adapter->organization_id, $adapter->adapter_key);

        if (! $registered instanceof RegisteredAdapter) {
            return back()->withErrors(['adapter' => __('infrastructure.errors.unknown_adapter')]);
        }

        $registered->checkHealth($redactor);

        return back()->with('status', __('infrastructure.adapters.check'));
    }

    /**
     * @return array<string, mixed>
     */
    private function row(RegisteredAdapter $registered): array
    {
        $descriptor = $registered->descriptor;
        $row = $registered->row;

        return [
            'id' => $row->id,
            'key' => $descriptor->key,
            'name' => $descriptor->name,
            'vendor' => $descriptor->vendor,
            'module' => $descriptor->module,
            'areas' => array_map(
                static fn (object $area): array => [
                    'value' => (string) $area->value,
                    'label' => (string) __('infrastructure.areas.'.$area->value),
                ],
                $descriptor->areas(),
            ),
            'reads' => $this->capabilities($descriptor->capabilities->reads()),
            'writes' => $this->capabilities($descriptor->capabilities->writes()),
            'permitted' => $registered->permitted()->toValues(),
            'enabled' => $row->enabled,
            'writesEnabled' => $row->writes_enabled,
            'health' => $row->health,
            'healthLabel' => $row->healthState() instanceof HealthState
                ? (string) __($row->healthState()->labelKey())
                : (string) __('health.states.unknown'),
            'healthMessage' => $row->health_message,
            'remoteVersion' => $row->remote_version,
            'supported' => $row->supported,
            'checkedAt' => $row->health_checked_at?->toIso8601String(),
            'limits' => [
                'perMinute' => $descriptor->limits->perMinute,
                'concurrency' => $descriptor->limits->concurrency,
                'batchSize' => $descriptor->limits->batchSize,
            ],
        ];
    }

    /**
     * @param  list<Capability>  $capabilities
     * @return list<array<string, mixed>>
     */
    private function capabilities(array $capabilities): array
    {
        return array_map(
            fn (Capability $capability): array => [
                'value' => $capability->value,
                'label' => $this->names->label($capability),
                'description' => $this->names->description($capability),
                'area' => $capability->area()->value,
                'highRisk' => $capability->isHighRisk(),
            ],
            $capabilities,
        );
    }

    /**
     * Adapters belong to the provider organization, not to the acting one.
     *
     * An adapter is this installation's connection to its own infrastructure. A
     * reseller does not own the provider's FortiGate, so a reseller's rows would
     * be a second set of decisions about somebody else's device — which is how a
     * boundary becomes a suggestion. The same reasoning `CurrentBrand` uses in the
     * client area, where the brand resolved is the seller's.
     *
     * Resolved **inside** the boundary on purpose. A reseller cannot see an
     * ancestor, so this returns null for them and the screen renders its empty
     * state: nothing about the provider's estate leaks, and no special case had to
     * be written to make that true. If resellers ever own infrastructure of their
     * own, that is a decision with an ADR rather than a change here.
     */
    private function providerOrganizationId(): ?string
    {
        $id = Organization::provider()->value('id');

        return is_string($id) ? $id : null;
    }

    private function authorizeFor(string $permission): void
    {
        if (! $this->actor->can($permission)) {
            throw new ForbiddenException(__('infrastructure.errors.not_permitted'));
        }
    }
}
