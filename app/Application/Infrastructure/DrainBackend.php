<?php

declare(strict_types=1);

namespace App\Application\Infrastructure;

use App\Domain\Infrastructure\Capability;
use App\Domain\Infrastructure\Contracts\LoadBalancerWriter;
use App\Domain\Infrastructure\Exceptions\BackendRefused;
use App\Domain\Infrastructure\LoadBalancing\Backend;
use App\Domain\Infrastructure\LoadBalancing\BackendState;
use App\Infrastructure\Identity\Models\StaffUser;
use App\Infrastructure\Resources\Models\ResourceNode;
use App\Support\Audit\Facades\Audit;
use App\Support\Logging\SecretRedactor;
use Throwable;

/**
 * Takes one backend out of a load balancer's rotation, or puts it back
 * (§9, §16).
 *
 * **A guarded action rather than a guarded change**, and the difference is
 * the plan's own decision: a drain is reversible and a firewall policy is
 * not, so requiring a second person to approve one would make a maintenance
 * window need somebody else awake at two in the morning. The gate is
 * `infrastructure.drain` plus the password challenge, on the route, with the
 * permission **above** `auth.recent` — the rule this repository has now
 * learned six times.
 *
 * **It reads the backend back.** The whole point of the action is the state
 * it produces, and a balancer that accepted the command and did nothing is
 * the failure worth catching; no adapter reports that about itself. The
 * re-read is also what the screen draws, so an operator watching the
 * connection count come down is watching the balancer rather than this
 * platform's last hourly sweep.
 *
 * **Draining something already draining is a success**, exactly as
 * `already_done` is for provisioning (ADR 0026) — which is what makes a
 * retry safe and a double-click harmless.
 *
 * **Nothing here waits.** Telling a balancer to stop sending new connections
 * takes a second; waiting for the existing ones to finish is the operator's
 * job, and a request that blocked until the count reached zero would be one
 * that hung for an hour.
 */
final readonly class DrainBackend
{
    public function __construct(
        private AdapterRegistry $registry,
        private SecretRedactor $redactor,
    ) {}

    public function drain(ResourceNode $node, StaffUser $actor, string $reason): Backend
    {
        return $this->act($node, $actor, $reason, draining: true);
    }

    public function undrain(ResourceNode $node, StaffUser $actor, string $reason): Backend
    {
        return $this->act($node, $actor, $reason, draining: false);
    }

    /**
     * Whether a drain would change anything, for a screen deciding which
     * button to offer.
     */
    public function isServing(ResourceNode $node): bool
    {
        $state = $node->attributes['state'] ?? null;

        return is_string($state) && (BackendState::tryFrom($state)?->isServing() ?? false);
    }

    private function act(ResourceNode $node, StaffUser $actor, string $reason, bool $draining): Backend
    {
        $attributes = $node->attributes ?? [];

        $adapterKey = $this->attribute($attributes, 'adapter');
        $listenerKey = $this->attribute($attributes, 'listener_key');
        $backendKey = $this->attribute($attributes, 'backend_key');

        if ($adapterKey === null || $listenerKey === null || $backendKey === null) {
            // A node written by a sweep that predates these attributes, or by
            // a module that does not set them. Refused by name rather than
            // guessed at from the node key: a balancer whose identifiers
            // contain a slash would make that parse wrong, and a drain sent
            // to the wrong backend is an outage.
            throw BackendRefused::notAddressable($node->label);
        }

        $registered = $this->registry->find($node->organization_id, $adapterKey);
        $adapter = $registered?->adapter();

        if (! $adapter instanceof LoadBalancerWriter) {
            throw BackendRefused::readOnly($node->label);
        }

        if (! $registered->permitted()->has(Capability::LoadBalancerDrainWrite)) {
            // The registry narrows rather than refuses, so a capability the
            // operator has not enabled is simply absent. The screen does not
            // offer the button; this is the belt.
            throw BackendRefused::writesNotEnabled($registered->descriptor->name);
        }

        try {
            $draining
                ? $adapter->drain($listenerKey, $backendKey)
                : $adapter->undrain($listenerKey, $backendKey);

            $backend = $adapter->backend($listenerKey, $backendKey);
        } catch (Throwable $exception) {
            $this->record($node, $actor, $reason, $draining, null, $exception);

            throw BackendRefused::balancerRefused(
                $node->label,
                $this->redactor->redactString($exception->getMessage()),
            );
        }

        if (! $backend instanceof Backend) {
            // It took the command and cannot now describe the backend. Not a
            // success: the operator is about to reboot a machine on the
            // strength of this answer.
            $this->record($node, $actor, $reason, $draining, null);

            throw BackendRefused::unverifiable($node->label);
        }

        $this->writeBack($node, $backend);
        $this->record($node, $actor, $reason, $draining, $backend);

        return $backend;
    }

    /**
     * Put the state the balancer just reported onto the node.
     *
     * So the list an operator returns to shows what they did rather than what
     * the last sweep saw. The connection count is telemetry and is not
     * written here — it changes by the second and the node is not where a
     * series lives.
     */
    private function writeBack(ResourceNode $node, Backend $backend): void
    {
        $attributes = $node->attributes ?? [];
        $attributes['state'] = $backend->state->value;

        $node->attributes = $attributes;
        $node->save();
    }

    private function record(
        ResourceNode $node,
        StaffUser $actor,
        string $reason,
        bool $draining,
        ?Backend $backend,
        ?Throwable $failure = null,
    ): void {
        Audit::action($draining ? 'infrastructure.backend.drained' : 'infrastructure.backend.undrained')
            ->by($actor)
            ->on($node)
            ->forOrganization($node->organization_id)
            // The reason is stored, because a reason a screen collects and an
            // endpoint discards is a sentence nobody reads — the rule the
            // cancellation queue learned.
            ->because($reason)
            ->withMetadata(array_filter([
                'state' => $backend?->state->value,
                'active_connections' => $backend?->activeConnections,
                'failed' => $failure === null
                    ? null
                    : $this->redactor->redactString($failure->getMessage()),
            ], static fn (mixed $value): bool => $value !== null))
            ->write();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function attribute(array $attributes, string $key): ?string
    {
        $value = $attributes[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }
}
