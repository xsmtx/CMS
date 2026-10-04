<?php

declare(strict_types=1);

namespace App\Domain\Infrastructure\Iac;

use Carbon\CarbonImmutable;

/**
 * A unit of infrastructure somebody keeps in code (§25).
 *
 * A Terraform workspace, an Ansible inventory, a Pulumi stack: one place with
 * a state of its own that a plan is produced against.
 *
 * **`stateSerial` is this family's fingerprint**, and the whole guarded
 * workflow rests on it. A device's configuration hashes to one string; a
 * workspace's state is a file that carries a serial which increases on every
 * write. The plan an approver read was produced against one serial, and
 * applying it against another is applying a plan to a world that has moved —
 * the same mistake `DeviceConfiguration::fingerprint()` exists to stop.
 *
 * **It is nullable, and null means refuse rather than guess.** An adapter that
 * cannot report a serial cannot say whether anything has changed, and a
 * workflow that proceeded anyway would be a workflow with its one safety check
 * switched off. `phase-j-plan.md` §3 states this, and it is the reason the
 * field is not an `int` with a zero default.
 *
 * **`lockedBy` is this family's "back up first".** There is no backup of a
 * workspace to take — the state is the provider's and the code is in somebody's
 * repository — but a workspace another run holds is a workspace where two
 * applies would interleave. Refusing on a lock is the precondition that makes
 * the apply safe, and it stands where the backup stands for a device.
 */
final readonly class IacWorkspace
{
    /**
     * @param  string  $key  How the adapter names this workspace, and what
     *                       every later call passes back.
     * @param  int|null  $stateSerial  Null when the adapter cannot say, which
     *                                 is a refusal rather than a zero.
     * @param  string|null  $lockedBy  Who or what holds it, in the provider's
     *                                 own words — never translated, because it
     *                                 is evidence in a conversation with
     *                                 somebody else's system.
     * @param  int|null  $resources  How many objects the state tracks, when the
     *                               adapter knows. Null is "it did not say".
     */
    public function __construct(
        public string $key,
        public string $name,
        public ?int $stateSerial = null,
        public ?string $lockedBy = null,
        public ?CarbonImmutable $lastAppliedAt = null,
        public ?int $resources = null,
    ) {}

    public function isLocked(): bool
    {
        return $this->lockedBy !== null && $this->lockedBy !== '';
    }
}
