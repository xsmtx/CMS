<?php

declare(strict_types=1);

namespace App\Domain\Infrastructure\Sites;

/**
 * Whether one version is behind another (§18).
 *
 * One place, because the core and every plugin and theme ask the same
 * question and two answers would eventually disagree — a site would then be
 * counted as up to date in the figure and out of date in the list.
 *
 * **Not knowing is not being up to date, and it is not being behind
 * either.** A panel that did not say what the latest version is has said
 * nothing, and both of the obvious shortcuts are wrong in the same direction:
 * treating the absence as "current" makes a fleet of unreadable sites look
 * perfectly maintained, which is the worst thing this feature could do.
 *
 * `version_compare` rather than a parser of our own. It already understands
 * `6.4.2`, `6.4`, `1.0.0-beta2` and the `-RC1` suffixes a plugin author will
 * put in a version string, and it answers the one question asked of it.
 */
final readonly class SiteVersion
{
    public static function isBehind(?string $current, ?string $latest): bool
    {
        if ($current === null || $latest === null) {
            return false;
        }

        $current = trim($current);
        $latest = trim($latest);

        if ($current === '' || $latest === '') {
            return false;
        }

        return version_compare($current, $latest, '<');
    }
}
