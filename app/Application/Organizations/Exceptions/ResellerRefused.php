<?php

declare(strict_types=1);

namespace App\Application\Organizations\Exceptions;

use App\Support\Errors\ErrorCode;
use App\Support\Errors\PlatformException;

/**
 * A reseller the installation will not create.
 *
 * In `Application` rather than `Domain` because both reasons are facts about
 * this installation rather than about the model: whether an address is
 * already taken, and whether a provider organization has been seeded. The
 * hierarchy's own rules live in `InvalidOrganizationHierarchy`.
 */
final class ResellerRefused extends PlatformException
{
    public static function emailTaken(string $email): self
    {
        return new self(__('organizations.errors.email_taken'), ['email' => $email]);
    }

    /**
     * No provider organization. Every installation is seeded with one, so
     * this is a database somebody has emptied — worth a sentence rather
     * than a null-pointer three frames later.
     */
    public static function noProvider(): self
    {
        return new self(__('organizations.errors.no_provider'));
    }

    /**
     * No administrator role. Every installation is seeded with the system
     * roles, so this is a database somebody has emptied — and a reseller
     * whose owner holds no role is a reseller whose owner can do nothing,
     * which is worth refusing rather than creating.
     */
    public static function noAdministratorRole(): self
    {
        return new self(__('organizations.errors.no_role'));
    }

    public function errorCode(): ErrorCode
    {
        return ErrorCode::PreconditionFailed;
    }
}
