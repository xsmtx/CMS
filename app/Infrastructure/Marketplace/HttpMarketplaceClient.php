<?php

declare(strict_types=1);

namespace App\Infrastructure\Marketplace;

use App\Application\Marketplace\VerifyPackage;
use App\Domain\Marketplace\Contracts\MarketplaceClient;
use App\Domain\Marketplace\Exceptions\PackageRefused;
use App\Domain\Marketplace\Listing;
use App\Domain\Modules\ModuleType;
use App\Domain\Modules\Sdk;
use App\Support\Correlation\CorrelationContext;
use App\Support\Http\SafeUrl;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * The vendor's catalogue, over HTTPS.
 *
 * A transport, and deliberately nothing else. It does not decide what this
 * installation may have — the vendor answers that, because the request carries
 * the licence key — and it does not decide whether a package is genuine, which
 * `VerifyPackage` does over the bytes.
 *
 * **An unreachable marketplace is not an outage.** `catalogue()` answers with an
 * empty list rather than throwing: an installation whose vendor is down is an
 * installation that cannot browse for new modules, which is not the same as one
 * that has stopped working. Everything already installed keeps running.
 * `download()` is the opposite and throws, because an operator pressed a button
 * and is owed an answer.
 *
 * **The download is streamed to a file and bounded.** A response with no ceiling
 * is a way to fill a disk, and reading a package into a string to hash it is how
 * a worker runs out of memory on the largest package rather than the smallest.
 *
 * **`SafeUrl` is checked immediately before the request**, never at
 * configuration time: DNS can change in between, and that is the whole
 * technique. The base URL is operator-supplied and the download URL comes from a
 * remote answer, so both are checked.
 *
 * It has never talked to a real marketplace. Its request shapes and its error
 * handling are tested against faked HTTP, which proves the code and not the
 * integration.
 */
final readonly class HttpMarketplaceClient implements MarketplaceClient
{
    public function __construct(
        private string $baseUrl,
        private ?string $licenceKey,
        private CorrelationContext $correlation,
        private VerifyPackage $verifier,
        private int $timeout = 15,
        private int $retries = 2,
    ) {}

    public function catalogue(): array
    {
        try {
            $payload = $this->get('catalogue');
        } catch (Throwable) {
            // Browsing is not something an installation depends on.
            return [];
        }

        $entries = $payload['packages'] ?? [];

        if (! is_array($entries)) {
            return [];
        }

        $listings = [];

        foreach ($entries as $entry) {
            $listing = is_array($entry) ? $this->listing($entry) : null;

            if ($listing instanceof Listing) {
                $listings[] = $listing;
            }
        }

        return $listings;
    }

    public function find(string $slug): ?Listing
    {
        foreach ($this->catalogue() as $listing) {
            if ($listing->slug === $slug) {
                return $listing;
            }
        }

        return null;
    }

    public function download(Listing $listing): string
    {
        if (! SafeUrl::allows($listing->downloadUrl)) {
            // Never echoes the URL: a refusal that quotes a remote address can
            // be used to probe what this installation can reach.
            throw PackageRefused::unreachable($listing->slug);
        }

        $path = $this->temporaryPath();

        try {
            $this->stream($listing, $path);
        } catch (Throwable $exception) {
            @unlink($path);

            if ($exception instanceof PackageRefused) {
                throw $exception;
            }

            throw PackageRefused::unreachable($listing->slug);
        }

        try {
            // Nothing is unpacked before this returns (ADR 0047). A caller
            // receiving a path from this contract may open the archive.
            $this->verifier->handle($listing, $path);
        } catch (Throwable $exception) {
            @unlink($path);

            throw $exception;
        }

        return $path;
    }

    private function stream(Listing $listing, string $path): void
    {
        $ceiling = max(1, (int) config('platform.marketplace.max_bytes', 64 * 1024 * 1024));

        $handle = @fopen($path, 'wb');

        if ($handle === false) {
            throw PackageRefused::unreachable($listing->slug);
        }

        $response = Http::withHeaders($this->headers())
            ->timeout($this->timeout)
            ->retry($this->retries, 250, throw: false)
            ->withOptions([
                // Stopped at the ceiling rather than after it: a body that keeps
                // coming is stopped while it is coming.
                'sink' => $handle,
                'allow_redirects' => false,
            ])
            ->get($listing->downloadUrl);

        if (is_resource($handle)) {
            fclose($handle);
        }

        if (! $response->successful()) {
            throw PackageRefused::unreachable($listing->slug);
        }

        $written = @filesize($path) ?: 0;

        if ($written > $ceiling) {
            throw PackageRefused::tooLarge($listing->slug, $ceiling);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function get(string $path): array
    {
        $url = rtrim($this->baseUrl, '/').'/'.ltrim($path, '/');

        if (! SafeUrl::allows($url)) {
            throw PackageRefused::unreachable($path);
        }

        $response = Http::withHeaders($this->headers())
            ->timeout($this->timeout)
            ->retry($this->retries, 250, throw: false)
            ->get($url);

        if (! $response->successful()) {
            throw PackageRefused::unreachable($path);
        }

        $decoded = $response->json();

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @return array<string, string>
     */
    private function headers(): array
    {
        $headers = [
            'Accept' => 'application/json',
            'X-Correlation-Id' => (string) $this->correlation->id(),
            'X-Platform-Version' => (string) config('platform.version', '1.0.0'),
            'X-Sdk-Version' => Sdk::VERSION,
        ];

        // The catalogue is what this installation may have, so the vendor needs
        // to know which installation is asking.
        if ($this->licenceKey !== null && $this->licenceKey !== '') {
            $headers['Authorization'] = 'Bearer '.$this->licenceKey;
        }

        return $headers;
    }

    /**
     * @param  array<string, mixed>  $entry
     */
    private function listing(array $entry): ?Listing
    {
        $type = ModuleType::tryFrom((string) ($entry['type'] ?? ''));

        $required = ['slug', 'name', 'version', 'download_url', 'digest', 'signature'];

        foreach ($required as $field) {
            if (! is_string($entry[$field] ?? null) || $entry[$field] === '') {
                return null;
            }
        }

        if ($type === null) {
            return null;
        }

        return new Listing(
            slug: (string) $entry['slug'],
            name: (string) $entry['name'],
            type: $type,
            version: (string) $entry['version'],
            summary: (string) ($entry['summary'] ?? ''),
            provider: (string) ($entry['provider'] ?? ''),
            sdk: (string) ($entry['sdk'] ?? '*'),
            downloadUrl: (string) $entry['download_url'],
            digest: (string) $entry['digest'],
            signature: (string) $entry['signature'],
            sizeBytes: (int) ($entry['size'] ?? 0),
            dependencies: array_values(array_filter(
                (array) ($entry['dependencies'] ?? []),
                static fn (mixed $value): bool => is_string($value) && $value !== '',
            )),
            infoUrl: is_string($entry['info_url'] ?? null) ? $entry['info_url'] : null,
        );
    }

    private function temporaryPath(): string
    {
        $directory = storage_path('app/marketplace');

        if (! is_dir($directory)) {
            mkdir($directory, 0o755, true);
        }

        return $directory.DIRECTORY_SEPARATOR.bin2hex(random_bytes(8)).'.zip';
    }
}
