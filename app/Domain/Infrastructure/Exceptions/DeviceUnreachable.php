<?php

declare(strict_types=1);

namespace App\Domain\Infrastructure\Exceptions;

use RuntimeException;

/**
 * The device did not answer, or answered something this adapter cannot read.
 *
 * Named separately rather than thrown as a generic failure, for the reason
 * `PackageRefused` gives: "it did not work" makes a box that is powered off,
 * a credential that has been rotated and a firmware that changed its API all
 * look identical in an audit log, and they are three different phone calls.
 *
 * **The message never carries the address or the credential.** It is rendered
 * on a screen and written to a log, like `AdapterHealth`, and the same rule
 * applies: a refusal that echoed the host back would put an internal address
 * into a customer-visible error the first time one of these escaped.
 */
final class DeviceUnreachable extends RuntimeException
{
    public static function noAnswer(string $target): self
    {
        return new self('The device '.$target.' did not answer.');
    }

    public static function refused(string $target): self
    {
        return new self('The device '.$target.' refused the credential.');
    }

    public static function unreadable(string $target, string $what): self
    {
        return new self('The device '.$target.' answered something unreadable: '.$what.'.');
    }
}
