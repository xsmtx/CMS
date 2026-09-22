<?php

declare(strict_types=1);

namespace App\Application\Billing\Exceptions;

use App\Support\Errors\ErrorCode;
use App\Support\Errors\PlatformException;

final class InvoiceNotIssuable extends PlatformException
{
    public static function withoutLines(): self
    {
        return new self(__('billing.errors.no_lines'));
    }

    public function errorCode(): ErrorCode
    {
        return ErrorCode::PreconditionFailed;
    }
}
