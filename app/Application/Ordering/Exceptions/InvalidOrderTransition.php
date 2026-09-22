<?php

declare(strict_types=1);

namespace App\Application\Ordering\Exceptions;

use App\Domain\Ordering\OrderStatus;
use App\Support\Errors\ErrorCode;
use App\Support\Errors\PlatformException;

/**
 * A status change the state machine does not permit.
 *
 * Carries the pair so the refusal reads as a statement about this order
 * rather than a generic failure.
 */
final class InvalidOrderTransition extends PlatformException
{
    public static function between(OrderStatus $from, OrderStatus $to): self
    {
        return new self(
            __('ordering.errors.invalid_transition', ['from' => $from->value, 'to' => $to->value]),
            [
                'from' => $from->value,
                'to' => $to->value,
                'allowed' => array_map(
                    static fn (OrderStatus $status): string => $status->value,
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
