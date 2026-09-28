<?php

declare(strict_types=1);

namespace App\Domain\Infrastructure\Sites;

/**
 * What kind of thing is installed on a site (§18).
 *
 * Two members, because a WordPress installation has exactly two things that
 * carry their own version and their own advisories. The core itself is not
 * one of them: it is on the installation rather than in this list, because a
 * site with no core version is a site this platform could not read at all,
 * and a site with no plugins is ordinary.
 */
enum ComponentKind: string
{
    case Plugin = 'plugin';

    case Theme = 'theme';

    public function labelKey(): string
    {
        return 'infrastructure.sites.components.'.$this->value;
    }
}
