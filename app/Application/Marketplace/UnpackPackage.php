<?php

declare(strict_types=1);

namespace App\Application\Marketplace;

use App\Domain\Marketplace\Exceptions\PackageRefused;
use App\Domain\Marketplace\Listing;
use RuntimeException;
use ZipArchive;

/**
 * An archive that has been proven, turned into a directory.
 *
 * Called only after `VerifyPackage`, and the ordering is the whole of ADR 0047:
 * an archive entry is a **path the archive chooses**, and `../../../.env` is a
 * valid entry name. Unpacking before proving would mean an unsigned stranger
 * choosing where this process writes.
 *
 * Entry by entry, and every one of them checked:
 *
 * - an absolute entry, or one containing `..`, is refused outright rather than
 *   normalised — a normaliser is a thing to find a bug in, and there is no
 *   legitimate package that needs either;
 * - the resolved destination must still be inside the target directory, which
 *   catches what the first check missed;
 * - a directory entry creates a directory and nothing else;
 * - nothing is followed. PHP's zip reader will not create a symlink from a
 *   stream copy, and the contents are written by this code rather than by
 *   `extractTo`, which would decide the paths itself.
 *
 * It unpacks into a **staging directory** and moves it into place only when
 * every entry has been written. A half-unpacked module directory is exactly
 * what `ModuleCatalogue` would read as a module on the next request.
 */
final readonly class UnpackPackage
{
    public function handle(Listing $listing, string $archivePath, string $destination): void
    {
        $zip = new ZipArchive;

        if ($zip->open($archivePath) !== true) {
            throw PackageRefused::unreadableArchive($listing->slug);
        }

        $staging = $destination.'.incoming-'.bin2hex(random_bytes(6));

        try {
            $this->write($zip, $listing, $staging);
        } catch (RuntimeException $exception) {
            $zip->close();
            $this->remove($staging);

            throw $exception;
        }

        $zip->close();

        // Replace only at the end, and only what we are replacing.
        $this->remove($destination);

        if (! @rename($staging, $destination)) {
            $this->remove($staging);

            throw PackageRefused::unreadableArchive($listing->slug);
        }
    }

    private function write(ZipArchive $zip, Listing $listing, string $staging): void
    {
        if (! is_dir($staging) && ! mkdir($staging, 0o755, true) && ! is_dir($staging)) {
            throw PackageRefused::unreadableArchive($listing->slug);
        }

        $root = realpath($staging);

        if ($root === false) {
            throw PackageRefused::unreadableArchive($listing->slug);
        }

        for ($index = 0; $index < $zip->numFiles; $index++) {
            $name = $zip->getNameIndex($index);

            if ($name === false || $name === '') {
                continue;
            }

            $this->writeEntry($zip, $listing, $index, $name, $root, $staging);
        }
    }

    private function writeEntry(
        ZipArchive $zip,
        Listing $listing,
        int $index,
        string $name,
        string $root,
        string $staging,
    ): void {
        $normalised = str_replace('\\', '/', $name);

        // Refused rather than normalised. There is no package that needs either
        // of these, and a normaliser is a thing to find a bug in.
        if (str_starts_with($normalised, '/')
            || preg_match('#(^|/)\.\.(/|$)#', $normalised) === 1
            || preg_match('#^[A-Za-z]:#', $normalised) === 1) {
            throw PackageRefused::unsafePath($listing->slug, $name);
        }

        $target = $staging.'/'.$normalised;

        if (str_ends_with($normalised, '/')) {
            if (! is_dir($target) && ! mkdir($target, 0o755, true) && ! is_dir($target)) {
                throw PackageRefused::unsafePath($listing->slug, $name);
            }

            return;
        }

        $parent = dirname($target);

        if (! is_dir($parent) && ! mkdir($parent, 0o755, true) && ! is_dir($parent)) {
            throw PackageRefused::unsafePath($listing->slug, $name);
        }

        // The second check, after the directories exist: what the first one
        // missed, the filesystem now answers.
        $resolvedParent = realpath($parent);

        if ($resolvedParent === false || ! str_starts_with($resolvedParent.DIRECTORY_SEPARATOR, $root.DIRECTORY_SEPARATOR)) {
            throw PackageRefused::unsafePath($listing->slug, $name);
        }

        $stream = $zip->getStream($name);

        if ($stream === false) {
            throw PackageRefused::unreadableArchive($listing->slug);
        }

        // Written by this code rather than by `extractTo`, which would decide
        // the paths itself and therefore decide the checks above are advisory.
        $handle = @fopen($target, 'wb');

        if ($handle === false) {
            fclose($stream);

            throw PackageRefused::unsafePath($listing->slug, $name);
        }

        stream_copy_to_stream($stream, $handle);

        fclose($handle);
        fclose($stream);

        unset($index);
    }

    private function remove(string $path): void
    {
        if (! is_dir($path)) {
            if (is_file($path)) {
                @unlink($path);
            }

            return;
        }

        foreach (array_diff((array) scandir($path), ['.', '..']) as $entry) {
            $this->remove($path.'/'.$entry);
        }

        @rmdir($path);
    }
}
