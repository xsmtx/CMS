<?php

declare(strict_types=1);

namespace App\Domain\Infrastructure\Sites;

/**
 * A plugin or a theme installed on a site (§18).
 *
 * **Out of date and vulnerable are different facts and never merge.** A
 * plugin three releases behind with nothing said against it is housekeeping
 * somebody does on a Thursday; a plugin with a published advisory is an
 * incident tonight, and the version number does not say which of the two this
 * is. A single "needs attention" column would make the four hundred of the
 * first kind hide the one of the second.
 *
 * **`vulnerable` is three-valued**, like a registry that did not answer
 * whether a domain is free (ADR 0028). `null` is "the source did not say" —
 * a panel with no vulnerability database attached, or an older version of one
 * — and it must not read as safe. `false` means something looked and found
 * nothing.
 *
 * **`active` is kept and never used to filter.** A deactivated plugin is
 * still a directory of PHP on somebody's hosting account, and the ones that
 * get exploited are exactly the ones nobody remembers installing.
 */
final readonly class SiteComponent
{
    public function __construct(
        public ComponentKind $kind,
        /** The slug the panel and the vendor both use: `woocommerce`. */
        public string $slug,
        public string $name,
        public ?string $version = null,
        public ?string $latestVersion = null,
        public bool $active = true,
        /** `null` is "nothing looked", never "nothing found". */
        public ?bool $vulnerable = null,
        /** Where somebody can read about it. A reference, never a copy. */
        public ?string $advisory = null,
    ) {}

    public function isOutdated(): bool
    {
        return SiteVersion::isBehind($this->version, $this->latestVersion);
    }
}
