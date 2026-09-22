<?php

declare(strict_types=1);

namespace App\Application\Catalog\Exceptions;

use App\Support\Errors\ErrorCode;
use App\Support\Errors\PlatformException;

final class CurrencyInUse extends PlatformException
{
    public static function asBase(string $code): self
    {
        return new self(__('catalog.errors.base_currency_locked', ['code' => $code]), ['code' => $code]);
    }

    public static function forPrices(string $code, int $prices): self
    {
        return new self(
            __('catalog.errors.currency_in_use', ['code' => $code, 'count' => $prices]),
            ['code' => $code, 'prices' => $prices],
        );
    }

    public function errorCode(): ErrorCode
    {
        return ErrorCode::PreconditionFailed;
    }
}
