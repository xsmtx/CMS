<?php

declare(strict_types=1);

namespace App\Domain\Branding;

/**
 * The three places a theme can be chosen for.
 *
 * Admin is included even though the handoff calls it optional: an operator
 * who has white-labelled the storefront and the portal and then shows their
 * own staff a panel with somebody else's name on it has not white-labelled
 * anything.
 */
enum Surface: string
{
    case Storefront = 'storefront';
    case Client = 'client';
    case Admin = 'admin';

    public function labelKey(): string
    {
        return 'branding.surfaces.'.$this->value;
    }

    /**
     * Where this surface's themes live on disk.
     */
    public function directory(): string
    {
        return base_path('themes/'.$this->value);
    }

    /**
     * The Blade namespace a surface's templates resolve under.
     *
     * Only the storefront has one today: the client and admin areas are
     * Inertia applications whose themes change tokens and assets rather
     * than templates. Giving them a namespace they do not use would be a
     * seam that looks load-bearing and is not.
     */
    public function viewNamespace(): ?string
    {
        return $this === self::Storefront ? 'storefront' : null;
    }
}
