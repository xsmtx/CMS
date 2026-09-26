<?php

declare(strict_types=1);

namespace App\Domain\Infrastructure\Network;

use Carbon\CarbonImmutable;

/**
 * A device's running configuration, and a fingerprint of it.
 *
 * Two jobs, and the second is the one that matters. §6's guarded workflow
 * computes its diff **against what the device says now**, immediately before
 * the apply, because a diff computed at request time and applied an hour later
 * is a diff against a device somebody else has edited. The fingerprint is how
 * that check is cheap: read it, compare it to the one the diff was built
 * against, and refuse if it moved.
 *
 * The fingerprint is a SHA-256 of the text, computed here rather than asked of
 * the adapter. An adapter that hashed its own configuration would be an
 * adapter that could disagree with the next one about what "unchanged" means,
 * and two answers to that question is one too many.
 *
 * **A configuration is not a secret store, and it contains secrets.** Device
 * configurations carry SNMP communities, RADIUS keys, pre-shared keys and
 * hashed passwords. Nothing here may be logged, and a backup written to disk
 * is written where `SecretRedactor` has already been asked; that is the
 * caller's rule and this docblock is where it is written down.
 */
final readonly class DeviceConfiguration
{
    public function __construct(
        public string $target,
        public string $text,
        public CarbonImmutable $retrievedAt,
        public ?string $format = null,
    ) {}

    /**
     * What this configuration is, as one comparable value.
     *
     * Normalised on line endings first: a device that answers `\r\n` over one
     * transport and `\n` over another is the same configuration twice, and a
     * fingerprint that said otherwise would refuse every apply on that box.
     */
    public function fingerprint(): string
    {
        return hash('sha256', str_replace(["\r\n", "\r"], "\n", $this->text));
    }
}
