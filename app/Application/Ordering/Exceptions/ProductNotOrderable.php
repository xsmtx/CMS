<?php

declare(strict_types=1);

namespace App\Application\Ordering\Exceptions;

use App\Support\Errors\ErrorCode;
use App\Support\Errors\PlatformException;

final class ProductNotOrderable extends PlatformException
{
    public static function retired(string $name): self
    {
        return new self(__('ordering.errors.product_not_orderable'), ['product' => $name]);
    }

    public static function soldOut(string $name): self
    {
        return new self(__('ordering.errors.product_sold_out'), ['product' => $name]);
    }

    /**
     * The seller does not offer this product.
     *
     * Not "it does not exist": for a reseller the two are the same answer
     * from outside, and saying which would tell a reseller's customer what
     * the provider's catalogue contains.
     */
    public static function notAvailable(string $productId): self
    {
        return new self(__('ordering.errors.product_unavailable'), ['product' => $productId]);
    }

    public function errorCode(): ErrorCode
    {
        return ErrorCode::PreconditionFailed;
    }
}
