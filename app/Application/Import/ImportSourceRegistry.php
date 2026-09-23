<?php

declare(strict_types=1);

namespace App\Application\Import;

use App\Domain\Import\Contracts\ImportSource;
use App\Infrastructure\Import\Sources\WhmcsImportSource;

/**
 * The legacy systems this installation can read from.
 *
 * One today. The registry exists anyway, for the same reason the registrar and
 * gateway registries do: the screen has to list what is available, and a screen
 * that listed one hard-coded name would be a screen somebody has to edit when
 * the second adapter lands.
 *
 * A source is only offered when the connection it needs is configured. An
 * operator choosing "WHMCS" and then being told there is no connection is a
 * round trip that did not have to happen.
 */
final readonly class ImportSourceRegistry
{
    /**
     * @return list<array{key: string, label: string, connection: string, configured: bool}>
     */
    public function available(): array
    {
        $connection = (string) config('platform.import.whmcs_connection', 'legacy');

        return [
            [
                'key' => 'whmcs',
                'label' => 'WHMCS',
                'connection' => $connection,
                'configured' => is_array(config('database.connections.'.$connection)),
            ],
        ];
    }

    /**
     * The adapter for a key, or null when this installation has no such source.
     */
    public function for(string $key): ?ImportSource
    {
        foreach ($this->available() as $source) {
            if ($source['key'] !== $key || ! $source['configured']) {
                continue;
            }

            return match ($key) {
                'whmcs' => new WhmcsImportSource($source['connection']),
                default => null,
            };
        }

        return null;
    }
}
