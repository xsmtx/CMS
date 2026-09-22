<?php

declare(strict_types=1);

namespace App\Domain\Provisioning;

/**
 * An upgrade or a downgrade, as the provider sees it.
 *
 * Both names are carried because some providers want the new package and
 * some want to be told what it is changing from, and an adapter should not
 * have to go and look.
 */
final readonly class PackageChange
{
    /**
     * @param  array<string, string>  $options
     */
    public function __construct(
        public string $fromPackage,
        public string $toPackage,
        public array $options = [],
    ) {}
}
