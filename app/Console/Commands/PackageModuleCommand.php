<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Infrastructure\Modules\ModuleCatalogue;
use Illuminate\Console\Command;
use SodiumException;
use ZipArchive;

/**
 * Turn a module directory into a signed package, and say what to publish.
 *
 * The other half of the marketplace. `InstallFromMarketplace` refuses anything
 * it cannot prove (ADR 0047), which is only useful if there is something that
 * can produce a proof — and that is this, run by the vendor, on a machine
 * holding the private key.
 *
 * **The private key is read from a path and never stored.** It does not belong
 * in this repository, in an environment file that gets copied around, or in a
 * CI variable that a build log can print. `--key` takes a path; the command
 * reads it, signs, and forgets it.
 *
 * The signature is over the **archive bytes as written**. Anything that rewrites
 * the file afterwards — a recompressing CDN, a build step that normalises zip
 * timestamps — invalidates it, which is the property that makes it worth having.
 *
 * It prints a catalogue entry rather than uploading one. Publishing is the
 * vendor's own business and this platform does not assume it owns the other end.
 */
final class PackageModuleCommand extends Command
{
    protected $signature = 'platform:package
        {slug : The module to package, as its manifest declares it}
        {--key= : Path to the Ed25519 signing key, raw or base64url}
        {--out= : Where to write the archive (default: storage/app/packages)}
        {--url= : The URL the archive will be served from, for the catalogue entry}';

    protected $description = 'Build and sign a module package, and print its catalogue entry';

    public function handle(ModuleCatalogue $catalogue): int
    {
        $slug = (string) $this->argument('slug');
        $manifest = $catalogue->all()[$slug] ?? null;

        if ($manifest === null) {
            $this->components->error("No module called [{$slug}] is on disk.");

            return self::FAILURE;
        }

        $source = $catalogue->pathFor($slug);

        if ($source === null || ! is_dir($source)) {
            $this->components->error("The module [{$slug}] has no directory.");

            return self::FAILURE;
        }

        $secret = $this->secretKey();

        // Empty named as well as null: libsodium refuses an empty key outright,
        // and a caught exception would read as a bad key rather than a missing
        // one.
        if ($secret === null || $secret === '') {
            return self::FAILURE;
        }

        $archive = $this->archivePath($slug, $manifest->version);

        if (! $this->build($source, $archive)) {
            $this->components->error('The archive could not be written.');

            return self::FAILURE;
        }

        $bytes = (string) file_get_contents($archive);

        try {
            $signature = sodium_crypto_sign_detached($bytes, $secret);
        } catch (SodiumException) {
            $this->components->error('That key is not an Ed25519 secret key.');

            return self::FAILURE;
        }

        $entry = [
            'slug' => $manifest->slug,
            'name' => $manifest->name,
            'type' => $manifest->type->value,
            'version' => $manifest->version,
            'summary' => (string) $manifest->description,
            'provider' => (string) $manifest->provider,
            'sdk' => $manifest->sdk->range,
            'download_url' => (string) ($this->option('url') ?: 'https://packages.example/'.basename($archive)),
            'digest' => hash('sha256', $bytes),
            'signature' => rtrim(strtr(base64_encode($signature), '+/', '-_'), '='),
            'size' => strlen($bytes),
            'dependencies' => $manifest->dependencies,
        ];

        $this->components->info('Packaged '.$slug.' '.$manifest->version);
        $this->line($archive);
        $this->newLine();
        $this->line(json_encode($entry, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }

    /**
     * Everything under the module directory, at paths relative to it.
     *
     * Relative, because the archive is unpacked into a directory of the
     * installation's choosing — an entry carrying the builder's own absolute
     * path is an entry the unpacker will refuse, and rightly.
     */
    private function build(string $source, string $archive): bool
    {
        @unlink($archive);

        $zip = new ZipArchive;

        if ($zip->open($archive, ZipArchive::CREATE) !== true) {
            return false;
        }

        $root = rtrim(str_replace('\\', '/', (string) realpath($source)), '/');

        foreach ($this->files($root) as $path) {
            $relative = ltrim(substr(str_replace('\\', '/', $path), strlen($root)), '/');

            $zip->addFile($path, $relative);
        }

        return $zip->close();
    }

    /**
     * @return list<string>
     */
    private function files(string $directory): array
    {
        $found = [];

        foreach (array_diff((array) scandir($directory), ['.', '..']) as $entry) {
            $path = $directory.'/'.$entry;

            // Nothing a version control system or an editor left behind.
            if (in_array($entry, ['.git', '.DS_Store', 'node_modules', 'vendor'], true)) {
                continue;
            }

            $found = [...$found, ...(is_dir($path) ? $this->files($path) : [$path])];
        }

        return $found;
    }

    private function archivePath(string $slug, string $version): string
    {
        $directory = (string) ($this->option('out') ?: storage_path('app/packages'));

        if (! is_dir($directory)) {
            mkdir($directory, 0o755, true);
        }

        return rtrim($directory, '/\\').DIRECTORY_SEPARATOR.$slug.'-'.$version.'.zip';
    }

    private function secretKey(): ?string
    {
        $path = (string) $this->option('key');

        if ($path === '' || ! is_readable($path)) {
            $this->components->error('Pass --key with a path to the Ed25519 signing key.');

            return null;
        }

        $contents = (string) file_get_contents($path);

        // Length before trim: a raw key is random bytes, and one in eight of
        // them begins or ends with something `trim()` would eat.
        if (strlen($contents) === SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
            return $contents;
        }

        $padded = strtr(trim($contents), '-_', '+/');
        $padded .= str_repeat('=', (4 - strlen($padded) % 4) % 4);

        $decoded = base64_decode($padded, true);

        if ($decoded === false || strlen($decoded) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
            $this->components->error('That key is not an Ed25519 secret key.');

            return null;
        }

        return $decoded;
    }
}
