<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Licensing\Contracts\LicenceClient;
use Throwable;

/**
 * The vendor's licence API, faked.
 *
 * The vendor's control plane is a separate application this repository does not
 * contain (ADR 0013), so what a test can stand in for is the **transport**.
 * Everything that matters — the signing, the verification, the state, the grace
 * arithmetic — is real in the tests that use this.
 *
 * `failWith` is how a test says "the vendor is down", which is the case the
 * whole grace period exists for and the one most likely to be got wrong.
 */
final class FakeLicenceClient implements LicenceClient
{
    public ?string $nextToken = null;

    public ?Throwable $failWith = null;

    /** @var list<array<string, mixed>> */
    public array $calls = [];

    public function activate(string $licenceKey, string $installationId, array $claims): string
    {
        return $this->answer('activate', $installationId, $claims);
    }

    public function heartbeat(string $licenceKey, string $installationId, array $claims): string
    {
        return $this->answer('heartbeat', $installationId, $claims);
    }

    public function deactivate(string $licenceKey, string $installationId): void
    {
        $this->calls[] = ['endpoint' => 'deactivate', 'installation_id' => $installationId];

        if ($this->failWith !== null) {
            throw $this->failWith;
        }
    }

    /**
     * @param  array<string, string>  $claims
     */
    private function answer(string $endpoint, string $installationId, array $claims): string
    {
        $this->calls[] = [
            'endpoint' => $endpoint,
            'installation_id' => $installationId,
            'claims' => $claims,
        ];

        if ($this->failWith !== null) {
            throw $this->failWith;
        }

        return $this->nextToken ?? '';
    }
}
