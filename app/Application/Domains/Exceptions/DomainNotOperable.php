<?php

declare(strict_types=1);

namespace App\Application\Domains\Exceptions;

use App\Domain\Domains\DomainOperation;
use App\Support\Errors\ErrorCode;
use App\Support\Errors\PlatformException;

/**
 * The operation cannot be asked for: no registrar, or a registrar that does
 * not do this.
 *
 * Refused before anything is attempted, because "the button was there and
 * then it did nothing" is worse than the button not being there.
 */
final class DomainNotOperable extends PlatformException
{
    public static function noRegistrar(): self
    {
        return new self((string) __('domains.errors.no_registrar'));
    }

    public static function unknownRegistrar(string $key): self
    {
        return new self(
            (string) __('domains.errors.unknown_registrar', ['registrar' => $key]),
            ['registrar' => $key],
        );
    }

    public static function unsupported(string $registrar, DomainOperation $operation): self
    {
        return new self(
            (string) __('domains.errors.unsupported_operation', [
                'registrar' => $registrar,
                'operation' => __($operation->labelKey()),
            ]),
            ['registrar' => $registrar, 'operation' => $operation->value],
        );
    }

    public static function wrongStatus(string $status): self
    {
        return new self((string) __('domains.errors.wrong_status'), ['status' => $status]);
    }

    public function errorCode(): ErrorCode
    {
        return ErrorCode::PreconditionFailed;
    }
}
