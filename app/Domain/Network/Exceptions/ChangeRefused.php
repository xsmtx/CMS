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
}
