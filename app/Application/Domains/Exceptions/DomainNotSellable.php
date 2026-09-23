<?php

declare(strict_types=1);

namespace App\Application\Domains\Exceptions;

use App\Support\Errors\ErrorCode;
use App\Support\Errors\PlatformException;

/**
 * The name cannot be sold: no price in this currency, a term the registry
 * will not accept, or a TLD that is not offered.
 *
 * Refused before a customer pays rather than after, because a registry's
 * complaint arrives as a numeric code days later.
 */
final class DomainNotSellable extends PlatformException
{
    public static function noPrice(string $tld, string $currency): self
    {
        return new self(
            (string) __('domains.errors.not_priced', ['tld' => $tld, 'currency' => $currency]),
            ['tld' => $tld, 'currency' => $currency],
        );
    }

    public static function termRefused(string $tld, int $years): self
    {
        return new self(
            (string) __('domains.errors.term_refused', ['tld' => $tld, 'years' => $years]),
            ['tld' => $tld, 'years' => $years],
        );
    }

    public static function alreadyHeld(string $name): self
    {
        return new self((string) __('domains.errors.already_held', ['name' => $name]), ['name' => $name]);
    }

    public function errorCode(): ErrorCode
    {
        return ErrorCode::PreconditionFailed;
    }
}
