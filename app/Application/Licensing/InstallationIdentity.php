<?php

declare(strict_types=1);

namespace App\Application\Licensing;

use App\Infrastructure\Platform\Models\PlatformState;
use Illuminate\Support\Str;

/**
 * Who this installation says it is.
 *
 * One UUID, written once, kept in `platform_state` — not the cache, not the
 * environment file. Three reasons, and the third is the interesting one:
 *
 * - a cache key vanishes on a Redis flush, and an installation that changed
 *   identity every deploy would exhaust its activation limit in a week;
 * - an environment variable is a thing somebody copies between servers
 *   while setting up staging, and then two installations claim one identity;
 * - it lives in the database **on purpose**, so that restoring a production
 *   backup into a second environment produces exactly that collision — two
 *   installations heartbeating one ID — and the licence server sees it. That
 *   is the anomaly it should see. An identity that regenerated itself on
 *   restore would hide the one event worth noticing.
 *
 * The environment claims sent alongside it are **normalised and dull**: the
 * host the panel answers on, the product version, the PHP major.minor. No
 * paths, no database name, no keys. An activation request is made over TLS to
 * a vendor, and a vendor does not need to know where the installation keeps
 * its files.
 */
final readonly class InstallationIdentity
{
    public const string STATE_KEY = 'licensing.installation_id';

    /**
     * The installation's UUID, minted on first ask.
     *
     * `firstOrCreate` rather than a check-then-write: two requests arriving
     * together on a fresh installation would otherwise mint two identities and
     * one would win at random.
     */
    public function id(): string
    {
        $row = PlatformState::query()->firstOrCreate(
            ['key' => self::STATE_KEY],
            ['value' => ['uuid' => (string) Str::uuid()], 'updated_at' => now()],
        );

        $value = $row->value;

        if (! is_array($value) || ! is_string($value['uuid'] ?? null)) {
            // A row somebody edited by hand, or written by an older version.
            // Re-minted rather than thrown: an unreadable identity is not a
            // reason for the installation to stop working, and the licence
            // server will see a new activation, which is visible.
            $uuid = (string) Str::uuid();

            $row->forceFill(['value' => ['uuid' => $uuid], 'updated_at' => now()])->save();

            return $uuid;
        }

        return $value['uuid'];
    }

    /**
     * What the vendor is told about this installation.
     *
     * @return array<string, string>
     */
    public function claims(): array
    {
        return [
            // The host, not the URL: a path or a query string would be
            // telling the vendor how somebody's reverse proxy is arranged.
            'host' => (string) (parse_url((string) config('app.url'), PHP_URL_HOST) ?: 'unknown'),
            'version' => (string) (config('app.version', 'dev')),
            'php' => PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION,
        ];
    }
}
