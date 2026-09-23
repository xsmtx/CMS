<?php

declare(strict_types=1);

namespace App\Domain\Domains\Exceptions;

use App\Support\Errors\ErrorCode;
use App\Support\Errors\PlatformException;

final class InvalidDomainName extends PlatformException
{
    public static function for(string $input): self
    {
        return new self((string) __('ordering.errors.invalid_domain'), ['domain' => $input]);
    }

    /**
     * A well-formed name whose extension this installation does not sell.
     *
     * Kept apart from a malformed one: a customer who typed `example.zzz`
     * has made a different mistake from one who typed `example`.
     */
    public static function unsupportedTld(string $input): self
    {
        return new self((string) __('domains.errors.unsupported_tld'), ['domain' => $input]);
    }

    public function errorCode(): ErrorCode
    {
        return ErrorCode::ValidationFailed;
    }
}
