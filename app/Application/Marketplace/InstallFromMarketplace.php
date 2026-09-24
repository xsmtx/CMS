<?php

declare(strict_types=1);

namespace App\Application\Marketplace;

use App\Application\Modules\InstallModule;
use App\Domain\Marketplace\Contracts\MarketplaceClient;
use App\Domain\Marketplace\Exceptions\PackageRefused;
use App\Domain\Marketplace\Listing;
use App\Infrastructure\Modules\Models\ModuleRecord;
use App\Infrastructure\Modules\ModuleCatalogue;
use App\Support\Audit\Facades\Audit;
use Illuminate\Database\Eloquent\Model;

/**
 * Fetch a package, and take note of it. Nothing of it runs.
 *
 * The fourth step ADR 0038 did not need: with a package copied into `modules/`
 * by hand, an operator chose the bytes; with a download they chose **a name in a
 * catalogue**, and something else chose the bytes. So fetching is its own
 * audited moment, before installing, which is itself before enabling.
 *
 * Still nothing executes here. `InstallModule` reads a manifest and writes a
 * row; migrations and registration belong to `EnableModule`, which remains the
 * one moment an operator agrees to run somebody else's code.
 *
 * The order is deliberate: **verify, unpack, then install.** Installing reads
 * the manifest off disk, so the bytes have to be there — which means the proof
 * has to have happened before them.
 */
final readonly class InstallFromMarketplace
{
    public function __construct(
        private MarketplaceClient $marketplace,
        private UnpackPackage $unpack,
        private ModuleCatalogue $catalogue,
        private InstallModule $install,
    ) {}

    public function handle(string $slug, ?Model $actor = null): ModuleRecord
    {
        if (! (bool) config('platform.modules.enabled', true)) {
            throw PackageRefused::marketplaceDisabled();
        }

        $listing = $this->marketplace->find($slug);

        if ($listing === null) {
            throw PackageRefused::notOffered($slug);
        }

        $this->assertReplaceable($listing);

        // Proven by the client before it hands back a path (ADR 0047).
        $archive = $this->marketplace->download($listing);

        $destination = $this->destinationFor($listing);

        try {
            $this->unpack->handle($listing, $archive, $destination);
        } finally {
            // The archive is inert and large. It goes whether or not the unpack
            // worked, because a temp directory full of half-verified downloads
            // is its own problem.
            @unlink($archive);
        }

        // The catalogue has been reading a directory that did not exist a moment
        // ago, and it memoises.
        $this->catalogue->forget();

        $manifest = $this->catalogue->find($listing->slug);

        if ($manifest === null) {
            // Unpacked, and the catalogue still cannot see it: the archive did
            // not contain a manifest at the path a module lives at.
            throw PackageRefused::slugMismatch($listing->slug, '(none)');
        }

        if ($manifest->slug !== $listing->slug) {
            throw PackageRefused::slugMismatch($listing->slug, $manifest->slug);
        }

        Audit::action('marketplace.package.fetched')
            ->by($actor)
            ->withMetadata([
                'slug' => $listing->slug,
                'version' => $listing->version,
                'provider' => $listing->provider,
                'digest' => $listing->digest,
            ])
            ->write();

        $record = $this->install->handle($listing->slug, $actor);

        // Provenance, so the marketplace can refuse to overwrite a module it did
        // not deliver — otherwise a catalogue entry could replace a
        // hand-installed package with its own.
        $record->forceFill([
            'source' => 'marketplace',
            'origin_digest' => $listing->digest,
        ])->save();

        return $record;
    }

    /**
     * Whether the marketplace may write over what is already there.
     *
     * A module installed from disk is somebody's deliberate act on a machine
     * they control, and the marketplace does not get to undo it by offering the
     * same slug.
     */
    private function assertReplaceable(Listing $listing): void
    {
        $existing = ModuleRecord::query()->where('slug', $listing->slug)->first();

        if ($existing instanceof ModuleRecord && $existing->source !== 'marketplace') {
            throw PackageRefused::notOurs($listing->slug);
        }

        if ($existing instanceof ModuleRecord) {
            // Replacing a marketplace module in place is an upgrade, and an
            // upgrade is its own screen and its own consent.
            throw PackageRefused::notOffered($listing->slug);
        }
    }

    /**
     * `modules/<provider>/<slug>`, with both parts reduced to a plain name.
     *
     * The slug is validated by the manifest reader, but this path is built from
     * the **catalogue's** answer, which is a remote service's data and is not
     * yet a manifest.
     */
    private function destinationFor(Listing $listing): string
    {
        $root = (string) config('platform.modules.path', base_path('modules'));

        $vendor = $this->plainName($listing->provider) ?: 'vendor';
        $slug = $this->plainName($listing->slug);

        if ($slug === '') {
            throw PackageRefused::slugMismatch($listing->slug, '(not a plain name)');
        }

        return rtrim($root, '/\\').DIRECTORY_SEPARATOR.$vendor.DIRECTORY_SEPARATOR.$slug;
    }

    /**
     * A directory name with nothing in it that means anything to a filesystem.
     *
     * No dots at all, which is stricter than a slug needs to be and is the
     * point: a provider called `..` with dots allowed is a path traversal built
     * out of a field a remote service filled in.
     */
    private function plainName(string $value): string
    {
        $lower = strtolower(trim($value));

        return trim((string) preg_replace('/[^a-z0-9-]+/', '-', $lower), '-');
    }
}
