<?php

declare(strict_types=1);

namespace App\Application\Resellers\Exceptions;

use App\Domain\Organizations\OrganizationType;
use RuntimeException;

/**
 * A movement the ledger will not record.
 *
 * Refused rather than corrected. An amount of zero, a negative amount or an
 * account that is not a reseller's are all a caller having got something
 * wrong, and a ledger that quietly fixed any of them would be a ledger whose
 * rows nobody can trust to mean what they say.
 */
final class ResellerEntryRefused extends RuntimeException
{
    public static function notAReseller(OrganizationType $type): self
    {
        return new self("A [{$type->value}] organization has no account with the provider.");
    }

    public static function notPositive(): self
    {
        return new self('A ledger amount is always positive; the kind decides direction.');
    }

    public static function unknownOrganization(string $id): self
    {
        return new self("No reseller [{$id}] is reachable from here.");
    }
}
