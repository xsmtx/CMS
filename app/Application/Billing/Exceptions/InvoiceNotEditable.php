<?php

declare(strict_types=1);

namespace App\Application\Billing\Exceptions;

use App\Support\Errors\ErrorCode;
use App\Support\Errors\PlatformException;

/**
 * Someone tried to change an issued invoice.
 *
 * The refusal names the alternative, because the person asking has a real
 * problem and "no" on its own does not solve it.
 */
final class InvoiceNotEditable extends PlatformException
{
    public static function issued(): self
    {
        return new self(__('billing.errors.not_editable'));
    }

    public function errorCode(): ErrorCode
    {
        return ErrorCode::Conflict;
    }
}
