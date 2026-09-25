<?php

declare(strict_types=1);

namespace App\Domain\Secrets\Exceptions;

use RuntimeException;

/**
 * A reference that cannot be stored, refused before it is.
 *
 * Every message names the part rather than the value: a reference is
 * assembled from things other people filled in, and one of those things is
 * occasionally the credential itself pasted into the wrong field.
 */
final class InvalidSecretReference extends RuntimeException
{
    public static function badPart(string $name, string $part): self
    {
        return new self(sprintf(
            'A secret reference\'s %s must be lowercase letters, digits and hyphens, at most 64 of them (%d given).',
            $name,
            mb_strlen($part),
        ));
    }

    public static function malformed(string $key): self
    {
        return new self(sprintf(
            'A secret reference is area/kind/owner; %d parts were given.',
            count(explode('/', $key)),
        ));
    }
}
