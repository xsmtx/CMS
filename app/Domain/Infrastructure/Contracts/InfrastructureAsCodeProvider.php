<?php

declare(strict_types=1);

namespace App\Domain\Infrastructure\Contracts;

use App\Domain\Infrastructure\Iac\IacPlan;
use App\Domain\Infrastructure\Iac\IacWorkspace;

/**
 * Something that keeps infrastructure in code and can say what it would do
 * (§25).
 *
 * Terraform, OpenTofu, Pulumi, Ansible. The read half, which is the half that
 * needs no approval: list the workspaces, say where one stands, and produce a
 * plan somebody can look at.
 *
 * **Core holds no code and will not.** The configuration lives in somebody's
 * repository and the state lives with whoever runs it; this platform asks for
 * a plan and gets back words, three counts and a serial. A product that
 * stored Terraform files would be a second place they could be edited, and the
 * two would disagree the first time anybody used git.
 *
 * **`plan()` does not apply anything, and an implementation must make sure of
 * it.** This is called while an operator is typing — a plan with a side effect
 * would be a side effect nobody approved, which is the exact shortcut around
 * §6 the whole family is arranged to prevent.
 *
 * Every method throws rather than returning a null each caller would have to
 * remember to check. A workspace that cannot be reached is a refusal, never an
 * empty answer: an empty answer read naively is an estate that has vanished.
 */
interface InfrastructureAsCodeProvider extends InfrastructureAdapter
{
    /**
     * Everything this adapter is configured to see.
     *
     * Used to populate the request form, so an operator picks a workspace
     * rather than typing its name. The list a control is drawn from and the
     * list a write is validated against are then the same list.
     *
     * @return list<IacWorkspace>
     */
    public function workspaces(): array;

    /**
     * Where one workspace stands right now.
     *
     * Called immediately before an apply, and the `stateSerial` it returns is
     * what the apply compares against the one the plan was built with. This is
     * the IaC equivalent of re-reading a device's configuration, and the only
     * safety the workflow has here.
     */
    public function describe(string $workspace): IacWorkspace;

    /**
     * What would happen.
     *
     * @param  string|null  $ref  The revision to plan against — a branch, a
     *                            tag or a commit — or null for whatever the
     *                            workspace is configured to track. Core never
     *                            invents one: "the ref somebody typed" and
     *                            "the default" are different intentions and
     *                            guessing would silently plan the wrong code.
     */
    public function plan(string $workspace, ?string $ref = null): IacPlan;
}
