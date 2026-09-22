<?php

declare(strict_types=1);

namespace App\Application\Catalog\Exceptions;

use App\Support\Errors\ErrorCode;
use App\Support\Errors\PlatformException;

/**
 * A product group that the acting organization does not own.
 *
 * Reported as not found rather than forbidden: confirming that a group
 * exists somewhere else in the installation is itself a disclosure.
 */
final class GroupOutsideBoundary extends PlatformException
{
    public static function forId(string $groupId): self
    {
        return new self(__('catalog.errors.unknown_group'), ['group_id' => $groupId]);
    }

    public function errorCode(): ErrorCode
    {
        return ErrorCode::NotFound;
    }
}
