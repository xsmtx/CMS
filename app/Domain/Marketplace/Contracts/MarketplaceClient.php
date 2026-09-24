<?php

declare(strict_types=1);

namespace App\Domain\Marketplace\Contracts;

use App\Domain\Marketplace\Exceptions\PackageRefused;
use App\Domain\Marketplace\Listing;

/**
 * The vendor's catalogue, as this installation sees it.
 *
 * **The marketplace is a separate application this repository does not
 * contain**, exactly as the licence control plane is. `docs/marketplace/api.md`
 * is the contract; `Tests\Support\FakeMarketplaceClient` is what the tests
 * drive; `HttpMarketplaceClient` is the implementation, and it has never spoken
 * to a real vendor.
 *
 * The catalogue is **what this installation may have**, not everything that
 * exists. The request carries the licence key and the vendor answers with the
 * subset it is entitled to — so core holds no list of paid modules and gates on
 * nothing. A catalogue that does not list something is not an outage; a gate
 * whose default is deny would be (ADR 0041).
 *
 * `download()` returns a path to a file that has been **proven** — the
 * implementation is responsible for the size ceiling, the digest and the
 * signature before it hands a path back (ADR 0047). A caller receiving a path
 * from this contract may unpack it.
 */
interface MarketplaceClient
{
    /**
     * Everything on offer, or an empty list when there is no vendor configured.
     *
     * Never throws for "there is no marketplace". An installation with no
     * marketplace URL is an ordinary installation, not a broken one.
     *
     * @return list<Listing>
     */
    public function catalogue(): array;

    public function find(string $slug): ?Listing;

    /**
     * Fetch and prove one package.
     *
     * @return string absolute path to a verified archive on local disk
     *
     * @throws PackageRefused
     */
    public function download(Listing $listing): string;
}
