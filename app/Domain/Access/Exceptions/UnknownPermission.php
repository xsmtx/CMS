<?php

declare(strict_types=1);

namespace App\Domain\Access\Exceptions;

use InvalidArgumentException;

final class UnknownPermission extends InvalidArgumentException
{
    public static function slug(string $slug): self
    {
        return new self("Permission [{$slug}] is not declared in the permission registry.");
    }
}
