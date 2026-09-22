<?php

declare(strict_types=1);

namespace App\Application\Crm\Exceptions;

use App\Domain\Crm\CustomerStatus;
use App\Support\Errors\ErrorCode;
use App\Support\Errors\PlatformException;

/**
 * A customer status change the state machine does not permit.
 *
 * Carries a real error code so the caller sees a 409 with
 * `invalid_state_transition` rather than a generic failure, on both the
 * browser and the API.
 */
final class InvalidCustomerTransition extends PlatformException
{
    public static function between(CustomerStatus $from, CustomerStatus $to): self
    {
        return new self(
            __('crm.invalid_transition', ['from' => $from->value, 'to' => $to->value]),
            [
                'from' => $from->value,
                'to' => $to->value,
                'allowed' => array_map(
                    static fn (CustomerStatus $status): string => $status->value,
                    $from->allowedTransitions(),
                ),
            ],
        );
    }

    public function errorCode(): ErrorCode
    {
        return ErrorCode::InvalidStateTransition;
    }
}
