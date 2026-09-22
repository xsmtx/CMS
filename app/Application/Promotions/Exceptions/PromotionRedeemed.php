<?php

declare(strict_types=1);

namespace App\Application\Promotions\Exceptions;

use App\Support\Errors\ErrorCode;
use App\Support\Errors\PlatformException;

final class PromotionRedeemed extends PlatformException
{
    public static function times(int $count): self
    {
        return new self(
            __('ordering.errors.promotion_redeemed', ['count' => $count]),
            ['redemptions' => $count],
        );
    }

    public function errorCode(): ErrorCode
    {
        return ErrorCode::PreconditionFailed;
    }
}
