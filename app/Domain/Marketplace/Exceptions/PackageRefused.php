<?php

declare(strict_types=1);

namespace App\Domain\Marketplace\Exceptions;

use RuntimeException;

/**
 * A package this installation will not put on its disk, and exactly which check
 * said so.
 *
 * **Named separately on purpose** (ADR 0047). "The download failed" would be one
 * message covering a mirror that truncated a file and somebody serving a package
 * they did not sign, and those two must never look the same in an audit log: the
 * first is an afternoon's annoyance and the second is an incident.
 *
 * No message ever echoes the URL back. A refusal that quotes a remote address is
 * a refusal that can be used to probe what this installation can reach.
 */
final class PackageRefused extends RuntimeException
{
    public static function notOffered(string $slug): self
    {
        return new self("The marketplace does not offer a package called [{$slug}].");
    }

    public static function marketplaceDisabled(): self
    {
        return new self(
            'This installation does not run third-party code: modules are switched off in its configuration.'
        );
    }

    public static function unreachable(string $slug): self
    {
        return new self("The vendor did not answer for [{$slug}].");
    }

    public static function tooLarge(string $slug, int $limitBytes): self
    {
        return new self(
            "The package [{$slug}] is larger than this installation accepts ({$limitBytes} bytes). "
            .'Nothing was written.'
        );
    }

    public static function digest(string $slug): self
    {
        return new self(
            "The package [{$slug}] does not match the checksum the catalogue gave for it. "
            .'It arrived damaged or it is not the package that was offered. Nothing was written.'
        );
    }

    public static function signature(string $slug): self
    {
        return new self(
            "The package [{$slug}] is not signed by this platform's vendor. Nothing was written."
        );
    }

    public static function unsigned(): self
    {
        return new self(
            'No packaging key is configured, so no downloaded package can be proven. '
            .'A module can still be placed in the modules directory by hand.'
        );
    }

    public static function unreadableArchive(string $slug): self
    {
        return new self("The package [{$slug}] is not an archive this platform can open.");
    }

    /**
     * Zip slip, and the reason extraction happens entry by entry.
     */
    public static function unsafePath(string $slug, string $entry): self
    {
        return new self(
            "The package [{$slug}] contains an entry [{$entry}] that would be written outside its own "
            .'directory. Nothing was written.'
        );
    }

    public static function slugMismatch(string $offered, string $declared): self
    {
        return new self(
            "The package offered as [{$offered}] declares itself to be [{$declared}]. "
            .'A package that may name itself may take over another one. Nothing was written.'
        );
    }

    public static function notOurs(string $slug): self
    {
        return new self(
            "The module [{$slug}] is already on disk and did not come from the marketplace, "
            .'so the marketplace will not replace it.'
        );
    }
}
