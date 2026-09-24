<?php

declare(strict_types=1);

namespace App\Application\Marketplace;

use App\Domain\Marketplace\Exceptions\PackageRefused;
use App\Domain\Marketplace\Listing;
use SodiumException;

/**
 * Proof that an archive is the one the vendor published.
 *
 * Ed25519 over the **raw archive bytes** against a public key shipped in the
 * distribution: the installation can prove a package came from the vendor and
 * cannot mint one, which is the licence token's property (ADR 0041) reused for
 * the reason ADR 0047 gives.
 *
 * Over the bytes, never over a re-read or a rewritten form of them. The licence
 * learned this the hard way — a signature over re-encoded JSON fails on a
 * different PHP version — and an archive has the same trap wearing a different
 * hat: a CDN that recompresses a response is serving different bytes, and
 * different bytes are not signed.
 *
 * **The digest is not the security control.** It catches a truncated transfer
 * and a corrupted mirror, and it is checked first because it is cheap and its
 * failure has a completely different meaning. The catalogue that named the
 * digest could itself be the attacker; only the signature says otherwise.
 *
 * There is no way to skip any of this. A setting for it would be a setting
 * somebody turns on to make an error go away.
 */
final readonly class VerifyPackage
{
    public function handle(Listing $listing, string $path): void
    {
        $size = @filesize($path);

        if ($size === false || $size <= 0) {
            throw PackageRefused::unreadableArchive($listing->slug);
        }

        if ($size > $this->ceiling()) {
            throw PackageRefused::tooLarge($listing->slug, $this->ceiling());
        }

        // Hashed from the file rather than from a string in memory: a package is
        // tens of megabytes and reading one into a variable to hash it is how a
        // worker runs out of memory on the largest package rather than the
        // smallest.
        $digest = @hash_file('sha256', $path);

        if ($digest === false || ! hash_equals(strtolower($listing->digest), strtolower($digest))) {
            throw PackageRefused::digest($listing->slug);
        }

        $this->assertSigned($listing, $path);
    }

    private function assertSigned(Listing $listing, string $path): void
    {
        $key = $this->publicKey();

        if ($key === null) {
            // Not a refusal of this package: a refusal to accept any downloaded
            // package at all, which is the honest answer when nothing can prove
            // one.
            throw PackageRefused::unsigned();
        }

        $signature = $this->decode($listing->signature);
        $bytes = @file_get_contents($path);

        // Every emptiness named, because libsodium refuses an empty argument
        // outright and a caught exception would read as a failed check rather
        // than as a malformed one.
        if ($signature === null || $signature === '' || $key === '' || $bytes === false || $bytes === '') {
            throw PackageRefused::signature($listing->slug);
        }

        try {
            $holds = sodium_crypto_sign_verify_detached($signature, $bytes, $key);
        } catch (SodiumException) {
            // A signature or key of the wrong length. Not a package.
            $holds = false;
        }

        if (! $holds) {
            throw PackageRefused::signature($listing->slug);
        }
    }

    /**
     * The vendor's packaging key, read from the distribution.
     *
     * **Not the licence key.** They prove different things — "this installation
     * is licensed" and "these bytes are ours" — and one compromise must not be
     * both.
     */
    private function publicKey(): ?string
    {
        $path = config('platform.marketplace.public_key_path');

        if (! is_string($path) || $path === '' || ! is_readable($path)) {
            return null;
        }

        $contents = (string) file_get_contents($path);

        if ($contents === '') {
            return null;
        }

        /*
         * The length is checked **before** anything is trimmed, and that is not
         * a nicety: a key is 32 random bytes, and roughly one in eight starts or
         * ends with a byte `trim()` treats as whitespace — 0x09, 0x0a, 0x0d,
         * 0x20 or 0x00. Trimming first would corrupt those keys and leave the
         * rest working, which is the worst kind of bug: it looks like a bad
         * signature, it depends on which key was generated, and a test with a
         * random key passes most of the time.
         */
        if (strlen($contents) === SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
            return $contents;
        }

        // Otherwise it is text: base64url, so a key can be committed as one.
        return $this->decode(trim($contents));
    }

    /**
     * Base64url, unpadded, and strict.
     *
     * Strict because a decoder that ignores stray characters decodes two
     * different strings to the same bytes, and a signature check over "whatever
     * that decoded to" is not a signature check.
     */
    private function decode(string $value): ?string
    {
        $padded = strtr(trim($value), '-_', '+/');
        $padded .= str_repeat('=', (4 - strlen($padded) % 4) % 4);

        $decoded = base64_decode($padded, true);

        return $decoded === false || $decoded === '' ? null : $decoded;
    }

    private function ceiling(): int
    {
        return max(1, (int) config('platform.marketplace.max_bytes', 64 * 1024 * 1024));
    }
}
