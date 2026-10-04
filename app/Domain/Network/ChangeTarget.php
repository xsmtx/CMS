<?php

declare(strict_types=1);

namespace App\Domain\Network;

/**
 * What a guarded change is about (§25).
 *
 * `network_changes` was built in Phase C for a device, and §25's "show
 * plans/diffs and require approval to apply" is that workflow described in
 * nine words. So this is a column on the existing table rather than a second
 * table: who asked, why, which ticket, what changed, who agreed and what the
 * thing said afterwards are identical questions, and two tables answering them
 * would be two queues an operator has to remember to look at.
 *
 * **Closed, deliberately.** `ResourceKind` is an open vocabulary because core
 * cannot know every noun a module will discover; this is not a noun, it is a
 * *workflow*, and each member decides which contract is used, which capability
 * is checked, what stands in for the fingerprint and what happens when the
 * verify fails. A member nothing in core could branch on would be a change
 * nothing could apply.
 *
 * The two differ in one way that matters and it is written down here because
 * it is easy to assume otherwise: **a device change can be rolled back and a
 * workspace change cannot.** Putting a device's old configuration back is one
 * call; putting a workspace back means applying the previous revision, which
 * is another apply, with its own plan and its own approval. So a failed verify
 * leaves a device `rolled_back` and a workspace `failed`, and the record says
 * which in words.
 */
enum ChangeTarget: string
{
    /** A firewall, switch or router, through `NetworkDeviceWriter`. */
    case Device = 'device';

    /**
     * A Terraform workspace, Ansible inventory or Pulumi stack, through
     * `InfrastructureAsCodeWriter`.
     */
    case Workspace = 'workspace';

    public function labelKey(): string
    {
        return 'network.targets.'.$this->value;
    }

    /**
     * Whether a failed verify can put the old state back.
     *
     * A method rather than a comparison at each call site, because the empty
     * case is the one somebody writing the check by hand gets backwards —
     * the same reason `MaintenanceWindow::covers()` exists.
     */
    public function canRollBack(): bool
    {
        return $this === self::Device;
    }
}
