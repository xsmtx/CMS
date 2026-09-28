<?php

declare(strict_types=1);

namespace App\Domain\Infrastructure\Exceptions;

use RuntimeException;

/**
 * A storage system that could not be read (§9).
 *
 * Its own type rather than `DeviceUnreachable` because the sentence reaches
 * an operator: "the device ceph did not answer" reads as a mistake in the
 * platform, and a cluster is not a device.
 *
 * **Throwing is the contract**, and `StorageProvider` says why in as many
 * words: an empty answer is taken literally by the sweep — every pool and
 * volume this source had has gone — so a source that is merely unreachable
 * must not be able to return one. This is what it returns instead.
 *
 * Nothing here ever echoes a URL or a credential back. The message is read
 * through `SecretRedactor` before it reaches a run record, which is the
 * safety net rather than the plan.
 */
final class StorageUnreachable extends RuntimeException
{
    public static function noAnswer(string $source): self
    {
        return new self('The storage system '.$source.' did not answer.');
    }

    public static function refused(string $source): self
    {
        return new self('The storage system '.$source.' refused the credential.');
    }

    public static function answered(string $source, int $status): self
    {
        return new self('The storage system '.$source.' answered '.$status.'.');
    }

    public static function unreadable(string $source, string $what): self
    {
        return new self('The storage system '.$source.' answered something unreadable: '.$what.'.');
    }
}
