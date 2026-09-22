<?php

declare(strict_types=1);

namespace App\Application\Catalog\Exceptions;

use App\Support\Errors\ErrorCode;
use App\Support\Errors\PlatformException;

/**
 * A product group that still holds products.
 *
 * The foreign key cascades, so deleting the group would take the products
 * with it. An operator tidying a menu does not expect to lose what customers
 * are buying, so the refusal is explicit.
 */
final class GroupNotEmpty extends PlatformException
{
    public static function holding(int $products): self
    {
        return new self(
            __('catalog.errors.group_not_empty', ['count' => $products]),
            ['products' => $products],
        );
    }

    public function errorCode(): ErrorCode
    {
        return ErrorCode::PreconditionFailed;
    }
}
