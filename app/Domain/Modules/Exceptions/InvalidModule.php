<?php

declare(strict_types=1);

namespace App\Domain\Modules\Exceptions;

use RuntimeException;

/**
 * A module this platform will not install, enable or keep running, and
 * exactly why.
 *
 * Every message names the module or the file. An operator who installed a
 * module and got "invalid module" has nothing to act on; one who got the
 * slug, the range and the platform version either fixes it or asks the
 * author a question that can be answered.
 */
final class InvalidModule extends RuntimeException
{
    public static function missingField(string $path, string $field): self
    {
        return new self("Module manifest [{$path}] is missing a [{$field}].");
    }

    public static function unreadable(string $path): self
    {
        return new self("Module manifest [{$path}] is not readable JSON.");
    }

    public static function unknownType(string $path, string $type): self
    {
        return new self("Module manifest [{$path}] declares an unknown type [{$type}].");
    }

    public static function badSlug(string $path, string $slug): self
    {
        return new self(
            "Module manifest [{$path}] declares a slug [{$slug}] that is not a plain name. "
            .'A slug reaches a directory, a route and a log channel.'
        );
    }

    public static function incompatibleSdk(string $slug, string $range, string $sdk): self
    {
        return new self(
            "Module [{$slug}] was built against SDK [{$range}] and this platform provides [{$sdk}]."
        );
    }

    public static function incompatiblePlatform(string $slug, string $range, string $platform): self
    {
        return new self(
            "Module [{$slug}] requires platform [{$range}] and this platform is [{$platform}]."
        );
    }

    public static function unreadableRange(string $slug, string $range): self
    {
        return new self(
            "Module [{$slug}] declares a version range [{$range}] this platform cannot read."
        );
    }

    public static function missingDependency(string $slug, string $dependency): self
    {
        return new self("Module [{$slug}] needs [{$dependency}], which is not enabled.");
    }

    public static function missingEntrypoint(string $slug, string $class): self
    {
        return new self("Module [{$slug}] names an entrypoint [{$class}] that does not exist.");
    }

    public static function notAModule(string $slug, string $class): self
    {
        return new self("Module [{$slug}] entrypoint [{$class}] does not implement the module contract.");
    }

    public static function extensionPointNotPermitted(string $slug, string $type, string $point): self
    {
        return new self(
            "Module [{$slug}] declares itself a [{$type}] and registers a [{$point}]. "
            .'A package that can quietly become something else is a package whose type nobody can rely on.'
        );
    }

    public static function inUse(string $slug, string $reason): self
    {
        return new self("Module [{$slug}] cannot be uninstalled: {$reason}.");
    }

    public static function notFound(string $slug): self
    {
        return new self("Module [{$slug}] is not on disk, or its manifest could not be read.");
    }

    public static function disabledByConfiguration(): self
    {
        return new self('This installation does not load modules.');
    }

    public static function missingConfiguration(string $slug, string $keys): self
    {
        return new self(
            "Module [{$slug}] needs [{$keys}] before it can be enabled. "
            .'A module registered without its configuration fails at the moment a customer is waiting.'
        );
    }

    public static function notInstalled(string $slug): self
    {
        return new self("Module [{$slug}] is not installed.");
    }

    public static function alreadyInstalled(string $slug): self
    {
        return new self("Module [{$slug}] is already installed.");
    }

    public static function downgrade(string $slug, string $from, string $to): self
    {
        return new self(
            "Module [{$slug}] on disk is version [{$to}] and the installed one is [{$from}]. "
            .'Upgrades only go forwards; a downgrade is an uninstall and an install.'
        );
    }
}
