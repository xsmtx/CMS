<?php

declare(strict_types=1);

namespace App\Domain\Infrastructure\Exceptions;

use App\Domain\Shared\Refused;

/**
 * A drain or an undrain that did not go ahead (§9).
 *
 * **Every reason is named separately**, the rule `PackageRefused` set: "the
 * drain failed" would make a balancer that is down, a module that can only
 * read, and a balancer that accepted the command and then could not describe
 * the backend look identical in an audit log. They are three different
 * mornings.
 *
 * `unverifiable` is the one worth reading twice. The balancer took the
 * command and cannot now say what the backend is doing, so this platform
 * cannot say either — and the operator is about to reboot a machine on the
 * strength of the answer. Treating it as a success would be the most
 * expensive optimism in the product.
 *
 * Nothing here echoes a URL or a credential. A balancer's own message reaches
 * this only through `SecretRedactor`.
 */
final class BackendRefused extends Refused
{
    public static function notAddressable(string $backend): self
    {
        return new self(
            'This platform does not know how to reach '.$backend.' on its balancer.',
            'infrastructure.loadbalancing.errors.not_addressable',
            ['backend' => $backend],
        );
    }

    public static function readOnly(string $backend): self
    {
        return new self(
            'The balancer holding '.$backend.' cannot be written to.',
            'infrastructure.loadbalancing.errors.read_only',
            ['backend' => $backend],
        );
    }

    public static function writesNotEnabled(string $adapter): self
    {
        return new self(
            'Writes are not enabled for '.$adapter.'.',
            'infrastructure.loadbalancing.errors.writes_not_enabled',
            ['adapter' => $adapter],
        );
    }

    public static function balancerRefused(string $backend, string $because): self
    {
        return new self(
            'The balancer refused to change '.$backend.': '.$because,
            'infrastructure.loadbalancing.errors.balancer_refused',
            ['backend' => $backend, 'because' => $because],
        );
    }

    public static function unverifiable(string $backend): self
    {
        return new self(
            'The balancer took the command and could not then describe '.$backend.'.',
            'infrastructure.loadbalancing.errors.unverifiable',
            ['backend' => $backend],
        );
    }
}
