<?php

declare(strict_types=1);

namespace App\Application\Billing\Exceptions;

use App\Support\Errors\ErrorCode;
use App\Support\Errors\PlatformException;

/**
 * A one-off charge that will not be recorded, and why.
 *
 * One constructor per reason, for the reason `PackageRefused` gives: a
 * currency the customer is not billed in, a service that belongs to somebody
 * else and a charge that has already been invoiced are three different
 * mistakes, and two of them are the operator's to fix on the spot.
 */
final class BillableItemRefused extends PlatformException
{
    public static function noQuantity(): self
    {
        return new self((string) __('billing.billables.errors.no_quantity'));
    }

    /**
     * There is no exchange rate anywhere in this product, so a charge in
     * another currency waits for an invoice that never comes.
     */
    public static function wrongCurrency(string $given, string $expected): self
    {
        return new self(
            (string) __('billing.billables.errors.wrong_currency', [
                'given' => $given,
                'expected' => $expected,
            ]),
            ['given' => $given, 'expected' => $expected],
        );
    }

    public static function notTheirService(): self
    {
        return new self((string) __('billing.billables.errors.not_their_service'));
    }

    /**
     * An invoice is frozen at issue (ADR 0023), so an item a line quotes
     * cannot be taken back — the line would point at nothing.
     */
    public static function alreadyCharged(): self
    {
        return new self((string) __('billing.billables.errors.already_charged'));
    }

    public function errorCode(): ErrorCode
    {
        return ErrorCode::PreconditionFailed;
    }
}
