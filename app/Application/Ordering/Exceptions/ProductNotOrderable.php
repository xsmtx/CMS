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

    public function errorCode(): ErrorCode
    {
        return ErrorCode::PreconditionFailed;
    }
}
