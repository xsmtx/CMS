<?php

declare(strict_types=1);

namespace App\Domain\Identity;

/**
 * Why a sign-in attempt was refused.
 *
 * Recorded in login history so an operator can tell a forgotten password
 * apart from a credential-stuffing run. The reason is never returned to the
 * caller: every failure produces the same message.
 */
enum LoginFailureReason: string
{
    case UnknownIdentity = 'unknown_identity';
    case InvalidPassword = 'invalid_password';
    case AccountSuspended = 'account_suspended';
    case AccountClosed = 'account_closed';
    case NoPortalAccess = 'no_portal_access';
    case OrganizationInactive = 'organization_inactive';
    case Throttled = 'throttled';
    case InvalidTwoFactorCode = 'invalid_two_factor_code';
    case InvalidRecoveryCode = 'invalid_recovery_code';

    public static function forStatus(AccountStatus $status): self
    {
        return match ($status) {
            AccountStatus::Suspended => self::AccountSuspended,
            AccountStatus::Closed => self::AccountClosed,
            AccountStatus::Active => self::InvalidPassword,
        };
    }
}
