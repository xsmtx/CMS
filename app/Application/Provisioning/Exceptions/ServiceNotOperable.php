<?php

declare(strict_types=1);

namespace App\Application\Provisioning\Exceptions;

use App\Domain\Provisioning\ServiceOperation;
use App\Support\Errors\ErrorCode;
use App\Support\Errors\PlatformException;

/**
 * The operation cannot be asked for: no module, or a module that does not
 * do this.
 *
 * Refused before anything is attempted, because "the button was there and
 * then it did nothing" is worse than the button not being there.
 */
final class ServiceNotOperable extends PlatformException
{
    public static function noModule(): self
    {
        return new self((string) __('provisioning.errors.no_module'));
    }

    public static function unknownModule(string $key): self
    {
        return new self(
            (string) __('provisioning.errors.unknown_module', ['module' => $key]),
            ['module' => $key],
        );
    }

    public static function unsupported(string $module, ServiceOperation $operation): self
    {
        return new self(
            (string) __('provisioning.errors.unsupported_operation', [
                'module' => $module,
                'operation' => __($operation->labelKey()),
            ]),
            ['module' => $module, 'operation' => $operation->value],
        );
    }

    public static function wrongStatus(string $status): self
    {
        return new self(
            (string) __('provisioning.errors.wrong_status'),
            ['status' => $status],
        );
    }

    public function errorCode(): ErrorCode
    {
        return ErrorCode::PreconditionFailed;
    }
}
