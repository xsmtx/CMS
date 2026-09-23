<?php

declare(strict_types=1);

namespace App\Application\Licensing;

/**
 * The vendor's public key, as shipped in this distribution.
 *
 * A class rather than a `config()` read at the point of use, for one reason:
 * the file has to be read from disk and a verification that did that inline
 * would read it on every request. It is read once and held.
 *
 * **Absent is a valid state.** A self-hosted installation with no commercial
 * relationship has no key, verifies nothing, and is not crippled for it — the
 * entitlements fall back to allowing everything, which is the dull default
 * this platform has had since Phase 11. A distribution that refused to boot
 * without a licence key would be a distribution nobody could evaluate.
 *
 * The key is public by definition: it is in the tarball, and printing it would
 * disclose nothing. It is still never returned to a screen or a health check,
 * because a page that prints key material teaches an operator that pages print
 * key material.
 */
final class LicencePublicKey
{
    private bool $loaded = false;

    private ?string $bytes = null;

    /**
     * The raw 32 bytes, or null when this distribution has no key.
     */
    public function bytes(): ?string
    {
        if ($this->loaded) {
            return $this->bytes;
        }

        $this->loaded = true;
        $this->bytes = $this->read();

        return $this->bytes;
    }

    public function exists(): bool
    {
        return $this->bytes() !== null;
    }

    private function read(): ?string
    {
        $path = config('platform.licensing.public_key_path');

        if (! is_string($path) || $path === '' || ! is_file($path)) {
            return null;
        }

        $contents = @file_get_contents($path);

        if ($contents === false) {
            return null;
        }

        $trimmed = trim($contents);

        // Base64 in a file, which is what a key looks like when somebody has
        // to paste it into a deployment. Raw bytes are accepted too, because
        // an installer that wrote the key with `sodium_crypto_sign_keypair`
        // output would produce those.
        $decoded = base64_decode($trimmed, true);

        if ($decoded !== false && strlen($decoded) === SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
            return $decoded;
        }

        return strlen($trimmed) === SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES ? $trimmed : null;
    }
}
