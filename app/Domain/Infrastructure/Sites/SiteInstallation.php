<?php

declare(strict_types=1);

namespace App\Domain\Infrastructure\Sites;

/**
 * One application installed on somebody's hosting account (§18).
 *
 * **The key is the panel's, not the URL.** A site's address changes when
 * somebody moves a domain, points it at a staging copy or finishes a
 * migration, and a node keyed on it would leave the old one behind as a site
 * that had apparently vanished — the same rule the network device's serial
 * settled, through a different door.
 *
 * **`application` is a word, not an enum.** This is a WordPress fleet by name
 * and a panel reports Joomla, Drupal, a Laravel application and whatever the
 * next toolkit learns about. An enum here would make core the authority on
 * which web applications exist, which is exactly what `ResourceKind` is open
 * for (ADR 0043).
 *
 * **Nothing here is stored in a table.** The versions go on the node's
 * attributes and the counts with them — §14's rule for the sixth time. A
 * site's plugin list is a fact about somebody else's system that changes
 * hourly, and a table of it is a time-series database nobody sized.
 */
final readonly class SiteInstallation
{
    /**
     * @param  list<SiteComponent>  $components
     */
    public function __construct(
        public string $key,
        public string $url,
        public string $application = 'wordpress',
        public ?string $version = null,
        public ?string $latestVersion = null,
        public ?string $phpVersion = null,
        public array $components = [],
        /**
         * The graph node this site lives on, where the panel knows it.
         *
         * A server's node key or a service's. Null is ordinary: a panel that
         * reports sites and not which machine they are on has still told us
         * something worth having, and inventing a parent would put a site
         * under a machine on a guess.
         */
        public ?string $deviceKey = null,
        /** Where on disk, which is how two sites on one domain are told apart. */
        public ?string $path = null,
    ) {}

    public function isCoreOutdated(): bool
    {
        return SiteVersion::isBehind($this->version, $this->latestVersion);
    }

    /**
     * How many components are behind, the core not among them.
     *
     * The core is counted separately everywhere it appears: "WordPress 5.9 on
     * a site with no outdated plugins" and "WordPress 6.6 with eleven" are
     * different problems and a single number would say neither.
     */
    public function outdatedComponents(): int
    {
        return count(array_filter(
            $this->components,
            static fn (SiteComponent $component): bool => $component->isOutdated(),
        ));
    }

    public function vulnerableComponents(): int
    {
        return count(array_filter(
            $this->components,
            static fn (SiteComponent $component): bool => $component->vulnerable === true,
        ));
    }

    /**
     * Whether anything looked for vulnerabilities at all.
     *
     * False means nobody asked, which is a different answer from "nothing was
     * found" — and the one the screen has to be able to give, or an
     * unconfigured vulnerability database reads as a clean bill of health for
     * the whole fleet.
     */
    public function knowsAboutVulnerabilities(): bool
    {
        return array_any(
            $this->components,
            static fn (SiteComponent $component): bool => $component->vulnerable !== null,
        );
    }
}
