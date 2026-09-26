<?php

declare(strict_types=1);

namespace App\Domain\Network\Exceptions;

use RuntimeException;

/**
 * A grant that will not be given, and why.
 *
 * One constructor per reason, like `ChangeRefused`: "the grant failed" makes
 * somebody trying to grant themselves access and somebody typing the wrong
 * date look identical in an audit log, and only one of those is worth a
 * conversation.
 */
final class GrantRefused extends RuntimeException
{
    /**
     * The rule a permission cannot express: a permission says who may grant
     * and cannot say to whom.
     */
    public static function ownGrant(): self
    {
        return new self('A grant has to be given to somebody other than the person giving it.');
    }

    public static function tooShort(): self
    {
        return new self('A grant of less than five minutes is a grant somebody is about to give again.');
    }

    public static function tooLong(int $maximumMinutes): self
    {
        return new self(
            'A grant may run for at most '.$maximumMinutes.' minutes. '
            .'Longer than that is a permission with extra steps, and this platform has roles for those.'
        );
    }

    public static function alreadyRevoked(): self
    {
        return new self('That grant has already ended.');
    }
}
