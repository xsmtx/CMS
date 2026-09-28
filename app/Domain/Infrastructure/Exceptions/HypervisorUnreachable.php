<?php

declare(strict_types=1);

namespace App\Domain\Infrastructure\Exceptions;

use RuntimeException;

/**
 * A hypervisor that could not be read or written (§10).
 *
 * Its own type, like `StorageUnreachable` and `BalancerUnreachable`, because
 * the sentence reaches an operator and a cluster is not a device.
 *
 * **Throwing is the contract.** An empty answer is taken literally by the
 * sweep — every machine this hypervisor had has gone — so a cluster that is
 * merely unreachable must not be able to return one.
 *
 * On the write side it is caught by `ChangeMachinePower`, wrapped in a
 * `PowerRefused` that names which refusal it was, and recorded on the audit
 * row through `SecretRedactor`.
 */
final class HypervisorUnreachable extends RuntimeException
{
    public static function noAnswer(string $source): self
    {
        return new self('The hypervisor '.$source.' did not answer.');
    }

    public static function refused(string $source): self
    {
        return new self('The hypervisor '.$source.' refused the credential.');
    }

    public static function answered(string $source, int $status): self
    {
        return new self('The hypervisor '.$source.' answered '.$status.'.');
    }

    public static function unreadable(string $source, string $what): self
    {
        return new self('The hypervisor '.$source.' answered something unreadable: '.$what.'.');
    }
}
