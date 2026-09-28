<?php

declare(strict_types=1);

namespace App\Application\Infrastructure;

use App\Domain\Infrastructure\Capability;
use App\Domain\Infrastructure\Contracts\HypervisorWriter;
use App\Domain\Infrastructure\Exceptions\PowerRefused;
use App\Domain\Infrastructure\Virtualisation\MachineState;
use App\Domain\Infrastructure\Virtualisation\PowerAction;
use App\Domain\Infrastructure\Virtualisation\VirtualMachine;
use App\Infrastructure\Identity\Models\StaffUser;
use App\Infrastructure\Resources\Models\ResourceNode;
use App\Support\Audit\Facades\Audit;
use App\Support\Logging\SecretRedactor;
use Throwable;

/**
 * Starts, stops or restarts one machine (§10).
 *
 * **The most destructive thing in this product.** It does not stop a
 * customer's service; it stops the machine several customers are on. The gate
 * is `infrastructure.power` plus the password challenge, with the permission
 * **above** `auth.recent` on the route, and the screen asks for the machine's
 * own name to be typed out — the bar `CompleteCancellation` already sets for
 * terminating one service, applied to something that takes down many.
 *
 * **It refuses to stop a machine that is already stopped**, and that is not
 * politeness. A `shutdown` sent to a machine somebody already shut is
 * harmless; a `power_off` sent to one that was started thirty seconds ago by
 * somebody else is not, and the two are indistinguishable from a stale page.
 * So the state is checked against the hypervisor's own answer at the moment of
 * the call rather than against the node the operator was looking at — the same
 * reason `ApplyNetworkChange` re-reads a device's fingerprint before it
 * applies a diff.
 *
 * **It reads the machine back.** A hypervisor that accepted a shutdown and did
 * nothing is the failure worth catching and none of them reports it. It does
 * not *wait*: a guest thinking about a shutdown can take minutes, and a
 * request that blocked would be one that hung.
 *
 * Every refusal is named separately, and every attempt — including the ones
 * that failed — is on the audit row with the reason the operator gave.
 */
final readonly class ChangeMachinePower
{
    public function __construct(
        private AdapterRegistry $registry,
        private SecretRedactor $redactor,
    ) {}

    public function handle(
        ResourceNode $node,
        PowerAction $action,
        StaffUser $actor,
        string $reason,
    ): VirtualMachine {
        $attributes = $node->attributes ?? [];

        $adapterKey = $this->attribute($attributes, 'adapter');
        $machineKey = $this->attribute($attributes, 'machine_key');

        if ($adapterKey === null || $machineKey === null) {
            throw PowerRefused::notAddressable($node->label);
        }

        $registered = $this->registry->find($node->organization_id, $adapterKey);
        $adapter = $registered?->adapter();

        if (! $adapter instanceof HypervisorWriter) {
            throw PowerRefused::readOnly($node->label);
        }

        if (! $registered->permitted()->has(Capability::VirtualMachinePowerWrite)) {
            throw PowerRefused::writesNotEnabled($registered->descriptor->name);
        }

        $before = $this->read($node, $adapter, $machineKey, $action, $actor, $reason);

        $this->refuseIfPointless($node, $before, $action, $actor, $reason);

        try {
            $adapter->power($machineKey, $action);
            $after = $adapter->machine($machineKey);
        } catch (Throwable $exception) {
            $this->record($node, $action, $actor, $reason, null, $exception);

            throw PowerRefused::hypervisorRefused(
                $node->label,
                $this->redactor->redactString($exception->getMessage()),
            );
        }

        if (! $after instanceof VirtualMachine) {
            $this->record($node, $action, $actor, $reason, null);

            throw PowerRefused::unverifiable($node->label);
        }

        $this->writeBack($node, $after);
        $this->record($node, $action, $actor, $reason, $after);

        return $after;
    }

    /**
     * What the hypervisor says *now*, not what the operator's page said.
     *
     * A page an operator has had open for ten minutes is a page somebody else
     * may have acted on. This is the same re-read `ApplyNetworkChange` does of
     * a device's fingerprint, for a smaller diff and the same reason.
     */
    private function read(
        ResourceNode $node,
        HypervisorWriter $adapter,
        string $machineKey,
        PowerAction $action,
        StaffUser $actor,
        string $reason,
    ): VirtualMachine {
        try {
            $machine = $adapter->machine($machineKey);
        } catch (Throwable $exception) {
            $this->record($node, $action, $actor, $reason, null, $exception);

            throw PowerRefused::hypervisorRefused(
                $node->label,
                $this->redactor->redactString($exception->getMessage()),
            );
        }

        if (! $machine instanceof VirtualMachine) {
            throw PowerRefused::missing($node->label);
        }

        return $machine;
    }

    /**
     * Stopping something already stopped, or starting something running.
     *
     * Refused rather than treated as `already_done`, which is the opposite of
     * what provisioning does (ADR 0026) and deliberate: a provisioning retry
     * that finds the account already created has found what it wanted, and an
     * operator who presses Shut down on a machine that is already off is
     * looking at a page that does not match the world. Telling them so is the
     * useful answer.
     */
    private function refuseIfPointless(
        ResourceNode $node,
        VirtualMachine $machine,
        PowerAction $action,
        StaffUser $actor,
        string $reason,
    ): void {
        $pointless = match ($action) {
            PowerAction::Start => $machine->state === MachineState::Running,
            PowerAction::Shutdown, PowerAction::PowerOff => $machine->state === MachineState::Stopped,
            // A reboot of a stopped machine is a start on most hypervisors and
            // an error on others. Refused here rather than guessed at.
            PowerAction::Reboot => $machine->state === MachineState::Stopped,
        };

        if (! $pointless) {
            return;
        }

        $this->record($node, $action, $actor, $reason, $machine);

        throw PowerRefused::alreadyThere($node->label, (string) __($machine->state->labelKey()));
    }

    private function writeBack(ResourceNode $node, VirtualMachine $machine): void
    {
        $attributes = $node->attributes ?? [];
        $attributes['state'] = $machine->state->value;

        $node->attributes = $attributes;
        $node->save();
    }

    private function record(
        ResourceNode $node,
        PowerAction $action,
        StaffUser $actor,
        string $reason,
        ?VirtualMachine $machine,
        ?Throwable $failure = null,
    ): void {
        Audit::action('infrastructure.machine.power')
            ->by($actor)
            ->on($node)
            ->forOrganization($node->organization_id)
            ->because($reason)
            ->withMetadata(array_filter([
                'action' => $action->value,
                'state' => $machine?->state->value,
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
