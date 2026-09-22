<?php

declare(strict_types=1);

namespace App\Support\Errors\Contracts;

use App\Support\Errors\ErrorCode;

/**
 * Implemented by exceptions that know how they should be presented to an API
 * client. Anything that does not implement this is mapped by status code.
 */
interface ProvidesErrorCode
{
    public function errorCode(): ErrorCode;
}
