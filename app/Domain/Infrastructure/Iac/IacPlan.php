<?php

declare(strict_types=1);

namespace App\Domain\Infrastructure\Iac;

use Carbon\CarbonImmutable;

/**
 * What would happen if this were applied (§25).
 *
 * The thing an approver actually reads, and the IaC family's answer to a
 * configuration diff. It is produced by the adapter rather than computed here,
 * because only the tool knows what its own plan means — and a platform that
 * diffed two state files and called the result a plan would be showing
 * somebody a change that does not match the one the apply performs.
 *
 * **`reference` is the plan itself, held by whoever made it.** Terraform's
 * saved plan is a binary artifact on a runner and Ansible's check run is a
 * transcript; neither belongs in this database, and copying either here would
 * be this platform holding a second copy of something it cannot verify. What
 * is stored is the id, and the apply hands it back — which is also what makes
 * "apply exactly the plan that was approved" mean something.
 *
 * **The counts are for the screen and the serial is for the safety.** Three
 * numbers tell an operator how frightened to be at a glance; `stateSerial` is
 * what `ApplyNetworkChange` compares before it does anything.
 *
 * **A plan that changes nothing is a real answer**, not an error. Somebody
 * else applied it, or the drift corrected itself, and saying so is more useful
 * than refusing. It is how the apply verifies afterwards, too: re-plan, and an
 * empty plan is the proof it took.
 */
final readonly class IacPlan
{
    public function __construct(
        public string $workspace,
        /** The human-readable plan, as the tool printed it. */
        public string $text,
        public ?string $reference = null,
        public ?int $stateSerial = null,
        public int $add = 0,
        public int $change = 0,
        public int $destroy = 0,
        public ?CarbonImmutable $createdAt = null,
    ) {}

    public function changesNothing(): bool
    {
        return $this->add === 0 && $this->change === 0 && $this->destroy === 0;
    }

    /**
     * Whether anything here destroys something.
     *
     * Kept apart from the other two counts because it is the one an operator
     * reads differently: adding six resources and destroying one are not the
     * same size of decision, and a single "12 changes" would hide which.
     */
    public function destroys(): bool
    {
        return $this->destroy > 0;
    }
}
