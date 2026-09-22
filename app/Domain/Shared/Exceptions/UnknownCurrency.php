<?php

declare(strict_types=1);

namespace App\Domain\Shared\Exceptions;

use InvalidArgumentException;

final class UnknownCurrency extends InvalidArgumentException
{
    public static function code(string $code): self
    {
        return new self("[{$code}] is not a known ISO 4217 currency code.");
    }
}
