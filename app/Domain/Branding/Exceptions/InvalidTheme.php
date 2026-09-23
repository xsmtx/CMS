<?php

declare(strict_types=1);

namespace App\Domain\Branding\Exceptions;

use RuntimeException;

/**
 * A theme this platform will not load, and exactly why.
 *
 * Every message names the file. An operator who installed a theme and got
 * "invalid theme" has nothing to act on; one who got the path and the
 * reason fixes it or deletes it.
 */
final class InvalidTheme extends RuntimeException
{
    public static function missingField(string $path, string $field): self
    {
        return new self("Theme manifest [{$path}] is missing a [{$field}].");
    }

    public static function unreadable(string $path): self
    {
        return new self("Theme manifest [{$path}] is not readable JSON.");
    }

    public static function executable(string $path): self
    {
        return new self(
            "Theme template [{$path}] contains raw PHP. Themes are templates, "
            .'not code; behaviour belongs in a module.'
        );
    }

    public static function incompatible(string $slug, string $range, string $platform): self
    {
        return new self(
            "Theme [{$slug}] declares compatibility [{$range}] and this platform is [{$platform}]."
        );
    }

    public static function missingParent(string $slug, string $parent): self
    {
        return new self("Theme [{$slug}] declares a parent [{$parent}] that is not installed.");
    }

    public static function circularParent(string $slug): self
    {
        return new self("Theme [{$slug}] has a circular parent chain.");
    }
}
