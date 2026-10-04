<?php

declare(strict_types=1);

namespace App\Domain\Infrastructure\Contracts;

use App\Domain\Infrastructure\Iac\IacOutcome;
use App\Domain\Infrastructure\Iac\IacPlan;

/**
 * Something that can run a plan somebody has approved (§25).
 *
 * Separate from `InfrastructureAsCodeProvider` for the reason
 * `NetworkDeviceWriter` is separate from its reader: read and write are
 * different capabilities, and an adapter that can show a plan and may not run
 * one says so by not implementing this.
 *
 * **Nothing in core calls this except `ApplyNetworkChange`**, from a change
 * record that was requested with a reason, approved by somebody other than
 * the requester, and checked against the workspace's state serial moments
 * before. There is no second path and there must not be one — an "auto-apply
 * on merge" setting would be the shortcut around the workflow, built first
 * (`phase-j-plan.md` §6 declines it by name).
 *
 * **`writes_enabled` is still the gate above all of it.** Implementing this
 * grants nothing: `AdapterRegistry::narrow()` makes `AutomationApplyWrite`
 * absent unless an operator turned it on for this adapter, so a screen cannot
 * offer a button the platform would then decline.
 */
interface InfrastructureAsCodeWriter extends InfrastructureAdapter
{
    /**
     * Run exactly this plan.
     *
     * The plan carries the reference the adapter gave out, which is what makes
     * "apply the plan that was approved" mean something: an implementation
     * that re-planned and ran the new result would be running something nobody
     * read. Where a tool cannot apply a saved plan, the adapter says so by
     * throwing rather than by quietly re-planning.
     *
     * The caller has already compared the workspace's state serial with the
     * one on this plan, immediately before this call. An implementation does
     * not repeat that check and must not skip a run because it believes
     * nothing has changed — that decision is core's, and an adapter with its
     * own idea of "unchanged" would be an adapter whose idea differed from the
     * one in the audit record.
     *
     * Throws rather than returning an unsuccessful outcome when the run could
     * not be started at all. `IacOutcome::$succeeded` is for a run that
     * happened and did not work.
     */
    public function apply(IacPlan $plan): IacOutcome;
}
