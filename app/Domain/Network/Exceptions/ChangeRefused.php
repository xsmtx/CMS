<?php

declare(strict_types=1);

namespace App\Domain\Network\Exceptions;

use RuntimeException;

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
 */
final class ChangeRefused extends RuntimeException
{
    public static function nothingToApply(): self
    {
        return new self('A change has to say what the configuration should become.');
    }

    public static function deviceNotReadable(string $device): self
    {
        return new self(
            'No adapter on this installation may read '.$device.', '
            .'so there is nothing to compare a change against.'
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
            .'the device says now.'
        );
    }

    public static function notApplicable(string $state): self
    {
        return new self('A change that is '.$state.' cannot be applied.');
    }

    public static function notDecidable(string $state): self
    {
        return new self('A change that is '.$state.' is already decided.');
    }

    public static function notWithdrawable(string $state): self
    {
        return new self('A change that is '.$state.' can no longer be withdrawn.');
    }

    /**
     * A change one person both asked for and agreed to is a change nobody
     * agreed to.
     */
    public static function ownApproval(): self
    {
        return new self('A change has to be approved by somebody other than the person who asked for it.');
    }

    public static function noWriter(string $device): self
    {
        return new self(
            'No adapter on this installation is permitted to change '.$device.'. '
            .'An operator turns that on per adapter, deliberately.'
        );
    }

    /**
     * The order is not a suggestion: a backup that failed is a change that
     * does not happen.
     */
    public static function backupFailed(string $device): self
    {
        return new self(
            'The configuration of '.$device.' could not be backed up, so nothing was applied.'
        );
    }
}
