<?php

declare(strict_types=1);

namespace App\Domain\Network\Exceptions;

use App\Domain\Shared\Refused;

/**
 * A device change that will not be going ahead, and why.
 *
 * Named separately, one constructor per reason, for the reason
 * `PackageRefused` gives: "the change failed" makes a box somebody else
 * edited, an approver who is the requester, a missing backup and an adapter
 * nobody permitted to write look identical in an audit log — and they are four
 * different conversations.
 *
 * None of these messages carries a configuration, an address or a credential.
 * They are rendered on a screen and written to a log.
 *
 * **The sentence somebody reads is `worded()`, not the message.** The message
 * is English and belongs in the log; it was the only thing here until the
 * staff API arrived, and a refusal reaching a phone in the wrong language is
 * worse than one reaching a browser, because there is no surrounding screen
 * to make sense of it.
 */
final class ChangeRefused extends Refused
{
    public static function nothingToApply(): self
    {
        return new self(
            'A change has to say what the configuration should become.',
            'network.errors.nothing_to_apply',
        );
    }

    public static function deviceNotReadable(string $device): self
    {
        return new self(
            'No adapter on this installation may read '.$device.', '
            .'so there is nothing to compare a change against.',
            'network.errors.device_not_readable',
            ['device' => $device],
        );
    }

    /**
     * The whole point of the guarded workflow: the device moved between the
     * diff somebody agreed to and the apply.
     */
    public static function deviceMoved(string $device): self
    {
        return new self(
            'The configuration on '.$device.' is not the one this change was '
            .'reviewed against. Request it again so the diff is against what '
            .'the device says now.',
            'network.errors.device_moved',
            ['device' => $device],
        );
    }

    public static function notApplicable(string $state): self
    {
        return new self(
            'A change that is '.$state.' cannot be applied.',
            'network.errors.not_applicable',
            ['state' => $state],
        );
    }

    public static function notDecidable(string $state): self
    {
        return new self(
            'A change that is '.$state.' is already decided.',
            'network.errors.not_decidable',
            ['state' => $state],
        );
    }

    public static function notWithdrawable(string $state): self
    {
        return new self(
            'A change that is '.$state.' can no longer be withdrawn.',
            'network.errors.not_withdrawable',
            ['state' => $state],
        );
    }

    /**
     * A change one person both asked for and agreed to is a change nobody
     * agreed to.
     */
    public static function ownApproval(): self
    {
        return new self(
            'A change has to be approved by somebody other than the person who asked for it.',
            'network.errors.own_approval',
        );
    }

    public static function noWriter(string $device): self
    {
        return new self(
            'No adapter on this installation is permitted to change '.$device.'. '
            .'An operator turns that on per adapter, deliberately.',
            'network.errors.no_writer',
            ['device' => $device],
        );
    }

    /**
     * The order is not a suggestion: a backup that failed is a change that
     * does not happen.
     */
    public static function backupFailed(string $device): self
    {
        return new self(
            'The configuration of '.$device.' could not be backed up, so nothing was applied.',
            'network.errors.backup_failed',
            ['device' => $device],
        );
    }

    public static function workspaceNotReadable(string $workspace): self
    {
        return new self(
            'No adapter on this installation may read '.$workspace.', '
            .'so there is no plan to show.',
            'network.errors.workspace_not_readable',
            ['workspace' => $workspace],
        );
    }

    /**
     * This family's "back up first".
     *
     * There is no backup of a workspace to take — the state is the provider's
     * and the code is in somebody's repository — but a workspace another run
     * holds is one where two applies would interleave. Refusing on the lock is
     * the precondition that makes the apply safe, and it stands where the
     * backup stands for a device.
     */
    public static function workspaceLocked(string $workspace, string $holder): self
    {
        return new self(
            $workspace.' is locked by '.$holder.', so nothing was applied.',
            'network.errors.workspace_locked',
            ['workspace' => $workspace, 'holder' => $holder],
        );
    }

    /**
     * The whole point of the guarded workflow, in this family's terms: the
     * state moved between the plan somebody agreed to and the apply.
     */
    public static function workspaceMoved(string $workspace): self
    {
        return new self(
            'The state of '.$workspace.' is not the one this plan was built '
            .'against. Ask for it again so the plan is against what the '
            .'workspace holds now.',
            'network.errors.workspace_moved',
            ['workspace' => $workspace],
        );
    }

    /**
     * An adapter that cannot say where a workspace stands cannot say whether
     * anything has changed, and a workflow that proceeded anyway would be one
     * with its only safety check switched off.
     */
    public static function noStateSerial(string $workspace): self
    {
        return new self(
            'The adapter for '.$workspace.' cannot say which version of the '
            .'state it is looking at, so a plan cannot be applied safely.',
            'network.errors.no_state_serial',
            ['workspace' => $workspace],
        );
    }

    public static function nothingToPlan(string $workspace): self
    {
        return new self(
            'The plan for '.$workspace.' changes nothing, so there is nothing to approve.',
            'network.errors.nothing_to_plan',
            ['workspace' => $workspace],
        );
    }

    public static function noRunner(string $workspace): self
    {
        return new self(
            'No adapter on this installation is permitted to run a plan for '
            .$workspace.'. An operator turns that on per adapter, deliberately.',
            'network.errors.no_runner',
            ['workspace' => $workspace],
        );
    }
}
