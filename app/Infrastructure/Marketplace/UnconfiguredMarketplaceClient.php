<?php

declare(strict_types=1);

namespace App\Infrastructure\Marketplace;

use App\Domain\Marketplace\Contracts\MarketplaceClient;
use App\Domain\Marketplace\Exceptions\PackageRefused;
use App\Domain\Marketplace\Listing;

/**
 * What an installation with no marketplace sees: nothing on offer.
 *
 * An empty catalogue rather than an error, because an air-gapped installation
 * and one whose operator simply never set a marketplace URL are both ordinary
 * installations. Copying a directory into `modules/` works exactly as it always
 * has.
 *
 * `download()` still refuses rather than returning a path, and says why in
 * terms of this installation rather than of the package: nothing is wrong with
 * the package, there is just nowhere to get one from.
 */
final readonly class UnconfiguredMarketplaceClient implements MarketplaceClient
{
    public function catalogue(): array
    {
        return [];
    }

    public function find(string $slug): ?Listing
    {
        unset($slug);

        return null;
    }

    public function download(Listing $listing): string
    {
        throw PackageRefused::notOffered($listing->slug);
    }
}
