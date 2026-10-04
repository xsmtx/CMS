<?php

declare(strict_types=1);

namespace App\Domain\Network\Exceptions;

use App\Domain\Shared\Refused;

/**
 * A grant that will not be given, and why.
 *
 * One constructor per reason, like `ChangeRefused`: "the grant failed" makes
 * somebody trying to grant themselves access and somebody typing the wrong
 * date look identical in an audit log, and only one of those is worth a
 * conversation.
 */
final class GrantRefused extends Refused
{
    /**
     * The rule a permission cannot express: a permission says who may grant
     * and cannot say to whom.
     */
    public static function ownGrant(): self
    {
        return new self(
            'A grant has to be given to somebody other than the person giving it.',
            'access.grant_errors.own_grant',
        );
    }

    public static function tooShort(): self
    {
        return new self(
            'A grant of less than five minutes is a grant somebody is about to give again.',
            'access.grant_errors.too_short',
        );
    }

    public static function tooLong(int $maximumMinutes): self
    {
        return new self(
            'A grant may run for at most '.$maximumMinutes.' minutes. '
            .'Longer than that is a permission with extra steps, and this platform has roles for those.',
            'access.grant_errors.too_long',
            ['maximumMinutes' => $maximumMinutes],
        );
    }

    public static function alreadyRevoked(): self
    {
        return new self(
            'That grant has already ended.',
            'access.grant_errors.already_revoked',
        );
    }
}
