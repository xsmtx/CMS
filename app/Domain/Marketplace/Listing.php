<?php

declare(strict_types=1);

namespace App\Domain\Marketplace;

use App\Domain\Modules\ModuleType;

/**
 * One package the vendor offers this installation.
 *
 * Everything needed to decide whether to fetch it and to prove what arrived:
 * the digest and the signature are part of the offer, not of the download, so a
 * response body cannot also be the thing that says what the response body should
 * have been.
 *
 * `downloadUrl` is the vendor's; it is still checked against the outbound rules
 * immediately before the request, because a catalogue is an answer from a remote
 * service and an answer from a remote service is data.
 *
 * There is no price here and no way to buy. Buying a module is a transaction
 * with the vendor, and this platform is not growing a second billing system
 * pointed at itself — `infoUrl` is where an operator goes to read about it.
 */
final readonly class Listing
{
    /**
     * @param  string  $digest  lower-case hex SHA-256 of the archive
     * @param  string  $signature  base64url Ed25519 signature over the archive bytes
     * @param  list<string>  $dependencies  slugs this package needs
     */
    public function __construct(
        public string $slug,
        public string $name,
        public ModuleType $type,
        public string $version,
        public string $summary,
        public string $provider,
        public string $sdk,
        public string $downloadUrl,
        public string $digest,
        public string $signature,
        public int $sizeBytes,
        public array $dependencies = [],
        public ?string $infoUrl = null,
    ) {}
}
