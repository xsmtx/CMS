<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Application\Marketplace\VerifyPackage;
use App\Domain\Marketplace\Contracts\MarketplaceClient;
use App\Domain\Marketplace\Listing;
use App\Domain\Modules\ModuleType;
use ZipArchive;

/**
 * A vendor, in a temporary directory.
 *
 * It builds real zip archives and signs them with a real Ed25519 keypair
 * generated per test, then runs the **real** `VerifyPackage` over what it hands
 * back — so a test that tampers with an archive or a digest exercises the same
 * code an operator would. A fake that skipped verification would prove that the
 * happy path works and nothing else, which is the opposite of what this fake is
 * for.
 *
 * Nothing is mocked below the contract. The archive on disk is a zip, the
 * signature is a signature, and the refusals are the product's own.
 */
final class FakeMarketplaceClient implements MarketplaceClient
{
    public readonly string $publicKey;

    /** @var list<Listing> */
    private array $listings = [];

    /** @var array<string, string> absolute archive paths, keyed by slug */
    private array $archives = [];

    private readonly string $secretKey;

    private readonly string $workspace;

    public function __construct(?string $workspace = null)
    {
        $pair = sodium_crypto_sign_keypair();

        $this->publicKey = sodium_crypto_sign_publickey($pair);
        $this->secretKey = sodium_crypto_sign_secretkey($pair);

        $this->workspace = $workspace ?? sys_get_temp_dir().'/infracms-marketplace-'.bin2hex(random_bytes(6));

        if (! is_dir($this->workspace)) {
            mkdir($this->workspace, 0o755, true);
        }
    }

    /**
     * Write the public half where the verifier will look for it.
     */
    public function publishKey(): string
    {
        $path = $this->workspace.'/marketplace.pub';

        file_put_contents($path, $this->publicKey);

        return $path;
    }

    /**
     * Offer a package built from a map of `relative path => contents`.
     *
     * @param  array<string, string>  $files
     */
    public function offer(
        string $slug,
        array $files,
        string $version = '1.0.0',
        ModuleType $type = ModuleType::AdminWidget,
        string $provider = 'InfraCMS',
        ?string $signWith = null,
        ?string $digest = null,
    ): Listing {
        $archive = $this->workspace.'/'.$slug.'-'.$version.'.zip';

        @unlink($archive);

        $zip = new ZipArchive;
        $zip->open($archive, ZipArchive::CREATE);

        foreach ($files as $path => $contents) {
            $zip->addFromString($path, $contents);
        }

        $zip->close();

        $bytes = (string) file_get_contents($archive);

        $listing = new Listing(
            slug: $slug,
            name: ucfirst(str_replace('-', ' ', $slug)),
            type: $type,
            version: $version,
            summary: 'A package for tests.',
            provider: $provider,
            sdk: '*',
            downloadUrl: 'https://packages.example/'.basename($archive),
            digest: $digest ?? hash('sha256', $bytes),
            // `signWith` lets a test sign with somebody else's key, which is the
            // only way to prove the signature check does anything.
            signature: $this->sign($bytes, $signWith),
            sizeBytes: strlen($bytes),
        );

        $this->listings[] = $listing;
        $this->archives[$slug] = $archive;

        return $listing;
    }

    /**
     * Change the bytes after the listing was built, leaving the digest and the
     * signature describing what used to be there.
     */
    public function tamper(string $slug, string $entry, string $contents): void
    {
        $zip = new ZipArchive;
        $zip->open($this->archives[$slug]);
        $zip->addFromString($entry, $contents);
        $zip->close();
    }

    public function catalogue(): array
    {
        return $this->listings;
    }

    public function find(string $slug): ?Listing
    {
        foreach ($this->listings as $listing) {
            if ($listing->slug === $slug) {
                return $listing;
            }
        }

        return null;
    }

    public function download(Listing $listing): string
    {
        // Copied, because the caller deletes what it is given and a test may
        // install the same package twice.
        $path = $this->workspace.'/download-'.bin2hex(random_bytes(4)).'.zip';

        copy($this->archives[$listing->slug], $path);

        // The real verifier, over the real bytes. A fake that skipped this
        // would prove only that the happy path works.
        app(VerifyPackage::class)->handle($listing, $path);

        return $path;
    }

    /**
     * A keypair that is not the vendor's, for the test that proves the
     * signature is checked at all.
     */
    public static function otherSecretKey(): string
    {
        return sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair());
    }

    private function sign(string $bytes, ?string $key): string
    {
        $signature = sodium_crypto_sign_detached($bytes, $key ?? $this->secretKey);

        return rtrim(strtr(base64_encode($signature), '+/', '-_'), '=');
    }
}
