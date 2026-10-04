<?php

declare(strict_types=1);

namespace App\Domain\Infrastructure\Exceptions;

use App\Domain\Shared\Refused;

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
final class PowerRefused extends Refused
{
    public static function notAddressable(string $machine): self
    {
        return new self(
            'This platform does not know how to reach '.$machine.' on its hypervisor.',
            'infrastructure.virtualisation.errors.not_addressable',
            ['machine' => $machine],
        );
    }

    public static function readOnly(string $machine): self
    {
        return new self(
            'The hypervisor running '.$machine.' cannot be written to.',
            'infrastructure.virtualisation.errors.read_only',
            ['machine' => $machine],
        );
    }

    public static function writesNotEnabled(string $adapter): self
    {
        return new self(
            'Writes are not enabled for '.$adapter.'.',
            'infrastructure.virtualisation.errors.writes_not_enabled',
            ['adapter' => $adapter],
        );
    }

    public static function missing(string $machine): self
    {
        return new self(
            'The hypervisor no longer knows about '.$machine.'.',
            'infrastructure.virtualisation.errors.missing',
            ['machine' => $machine],
        );
    }

    /**
     * Stopping something already stopped is refused rather than treated as
     * `already_done` — the opposite of provisioning (ADR 0026) and
     * deliberate: an operator pressing Shut down on a machine that is
     * already off is looking at a page that does not match the world.
     */
    public static function alreadyThere(string $machine, string $state): self
    {
        return new self(
            $machine.' is already '.$state.'.',
            'infrastructure.virtualisation.errors.already_there',
            ['machine' => $machine, 'state' => $state],
        );
    }

    public static function hypervisorRefused(string $machine, string $because): self
    {
        return new self(
            'The hypervisor refused to change '.$machine.': '.$because,
            'infrastructure.virtualisation.errors.hypervisor_refused',
            ['machine' => $machine, 'because' => $because],
        );
    }

    public static function unverifiable(string $machine): self
    {
        return new self(
            'The hypervisor took the command and could not then describe '.$machine.'.',
            'infrastructure.virtualisation.errors.unverifiable',
            ['machine' => $machine],
        );
    }
}
