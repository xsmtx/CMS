<?php

declare(strict_types=1);

namespace App\Infrastructure\Licensing;

use App\Domain\Licensing\Contracts\LicenceClient;
use App\Domain\Licensing\Exceptions\LicenceUnreachable;

/**
 * The client for an installation with no licence server.
 *
 * It throws. That is the honest binding: the alternative is a null object that
 * returns a token nobody signed, and a token nobody signed is the one thing
 * this whole context exists to refuse.
 *
 * Nothing calls it in normal operation — `Entitlements` is bound to the
 * unrestricted implementation on the same condition, so an unlicensed
 * installation never asks a licence server anything. It exists so that an
 * operator who reaches the Licence screen on such an installation gets "no
 * licence server is configured" rather than a type error.
 */
final readonly class UnconfiguredLicenceClient implements LicenceClient
{
    public function activate(string $licenceKey, string $installationId, array $claims): string
    {
        throw LicenceUnreachable::notConfigured();
    }

    public function heartbeat(string $licenceKey, string $installationId, array $claims): string
    {
        throw LicenceUnreachable::notConfigured();
    }

    public function deactivate(string $licenceKey, string $installationId): void
    {
        throw LicenceUnreachable::notConfigured();
    }
}
