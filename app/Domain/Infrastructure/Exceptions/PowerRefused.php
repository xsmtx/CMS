<?php

declare(strict_types=1);

namespace App\Domain\Infrastructure\Exceptions;

use RuntimeException;

/**
 * A power action that did not go ahead (§10).
 *
 * **Every reason named separately**, the rule `PackageRefused` set and
 * `BackendRefused` repeated. On the single most consequential action in this
 * product, "the power action failed" in an audit log would be the least
 * useful sentence anybody ever read: a hypervisor that is down, a module that
 * can only read, a machine that was already off and a hypervisor that took
 * the command and did nothing are four different mornings.
 *
 * `unverifiable` is the one to read twice. The hypervisor accepted a shutdown
 * and cannot now say what the machine is doing, so this platform cannot say
 * either — and somebody is about to go and work on it. Treating that as a
 * success would be the most expensive optimism in the product.
 *
 * Nothing here echoes a URL or a credential; a hypervisor's own message
 * reaches this only through `SecretRedactor`.
 */
final class PowerRefused extends RuntimeException
{
    public static function notAddressable(string $machine): self
    {
        return new self('This platform does not know how to reach '.$machine.' on its hypervisor.');
    }

    public static function readOnly(string $machine): self
    {
        return new self('The hypervisor running '.$machine.' cannot be written to.');
    }

    public static function writesNotEnabled(string $adapter): self
    {
        return new self('Writes are not enabled for '.$adapter.'.');
    }

    public static function missing(string $machine): self
    {
        return new self('The hypervisor no longer knows about '.$machine.'.');
    }

    public static function alreadyThere(string $machine, string $state): self
    {
        return new self($machine.' is already '.$state.'.');
    }

    public static function hypervisorRefused(string $machine, string $because): self
    {
        return new self('The hypervisor refused to change '.$machine.': '.$because);
    }

    public static function unverifiable(string $machine): self
    {
        return new self('The hypervisor took the command and could not then describe '.$machine.'.');
    }
}
