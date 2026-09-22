<?php

declare(strict_types=1);

namespace App\Application\Provisioning\Exceptions;

use App\Support\Errors\ErrorCode;
use App\Support\Errors\PlatformException;

/**
 * Nowhere to put it.
 *
 * Deliberately an exception rather than a null return: a service that
 * silently lands nowhere is a service nobody notices is missing. The caller
 * turns this into a `failed` state with a reason an operator can act on.
 */
final class PlacementFailed extends PlatformException
{
    public static function noServers(string $group): self
    {
        return new self(
            (string) __('provisioning.errors.no_servers', ['group' => $group]),
            ['group' => $group],
        );
    }

    public static function noCapacity(string $group): self
    {
        return new self(
            (string) __('provisioning.errors.no_capacity', ['group' => $group]),
            ['group' => $group],
        );
    }

    public static function manualGroup(string $group): self
    {
        return new self(
            (string) __('provisioning.errors.manual_placement', ['group' => $group]),
            ['group' => $group],
        );
    }

    public static function noGroup(): self
    {
        return new self((string) __('provisioning.errors.no_group'));
    }

    public function errorCode(): ErrorCode
    {
        return ErrorCode::PreconditionFailed;
    }
}
