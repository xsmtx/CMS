<?php

declare(strict_types=1);

namespace App\Domain\Domains\Exceptions;

use App\Support\Errors\ErrorCode;
use App\Support\Errors\PlatformException;

final class InvalidDomainName extends PlatformException
{
    public static function for(string $input): self
    {
        return new self(__('ordering.errors.invalid_domain'), ['domain' => $input]);
    }

    public function errorCode(): ErrorCode
    {
        return ErrorCode::ValidationFailed;
    }
}
