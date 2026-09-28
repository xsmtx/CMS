<?php

declare(strict_types=1);

namespace InfraCMS\SitesWptoolkit;

use App\Domain\Health\HealthState;
use App\Domain\Infrastructure\AdapterHealth;
use App\Domain\Infrastructure\Capability;
use App\Domain\Infrastructure\CapabilitySet;
use App\Domain\Infrastructure\Contracts\SiteProvider;
use App\Domain\Infrastructure\Exceptions\SiteInventoryUnavailable;
use App\Domain\Infrastructure\RateLimits;
use App\Domain\Infrastructure\Sites\ComponentKind;
use App\Domain\Infrastructure\Sites\SiteComponent;
use App\Domain\Infrastructure\Sites\SiteInstallation;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * WP Toolkit, on Plesk or cPanel.
 *
 * Five things about that API that only a careful read turns up, each pinned
 * by a test:
 *
 * - **The instance id is the identity and the URL is not.** `siteUrl` changes
 *   the moment somebody finishes a migration or points a domain at a staging
 *   copy, and a fleet keyed on it would show the old address as a site that
 *   had vanished and the new one as a site that had appeared.
 * - **Plugins and themes come from two endpoints, and both take an instance
 *   filter.** Asking per site is four hundred requests on a busy server, so
 *   both are fetched once for the whole panel and grouped by `instanceId` —
 *   which is the same shape `monitoring-prometheus` exists to demonstrate.
 * - **`isInstalled` and `status` are not the same question.** A plugin can be
 *   present and deactivated, and the deactivated ones are exactly the ones
 *   nobody remembers installing and nobody updates. They are kept, with
 *   `active` false.
 * - **The vulnerability list is optional and its absence is not a zero.**
 *   Older toolkits and unlicensed ones have no `vulnerabilities` field at
 *   all; reading that as "nothing is wrong" would give a whole fleet a clean
 *   bill of health it never earned, so a component whose advisories nobody
 *   looked at carries `null`.
 * - **A panel with no toolkit answers 404 for ever**, which is a different
 *   thing from an outage — an operator reading "did not answer" would go and
 *   look at the network. `SiteInventoryUnavailable::notInstalled()` says so.
 *
 * **Nothing here updates anything.** §18 asks for update and maintenance
 * operations and WP Toolkit would accept them over this same API; they need
 * the guarded workflow that exists for network devices and not for somebody
 * else's website, and an adapter that offered the write before the workflow
 * existed would be the shortcut around it, built first.
 *
 * It has never talked to a real panel. Every request shape and every parse
 * here is tested against faked HTTP, which proves the code and not the
 * integration.
 */
final readonly class WpToolkitProvider implements SiteProvider
{
    /**
     * @param  Closure(): ?string  $token
     */
    public function __construct(
        private string $baseUrl,
        private Closure $token,
        private ?string $serverNode = null,
        private bool $verifyTls = true,
        private int $timeout = 20,
    ) {}

    public function key(): string
    {
        return 'wptoolkit';
    }

    public function name(): string
    {
        return 'WP Toolkit';
    }

    public function vendor(): string
    {
        return 'Plesk';
    }

    public function capabilities(): CapabilitySet
    {
        // Two reads and no write. The updates this toolkit would accept wait
        // for a guarded workflow for changing somebody else's site.
        return CapabilitySet::of([
            Capability::SiteInventoryRead,
            Capability::SiteVulnerabilityRead,
        ]);
    }

    public function limits(): RateLimits
    {
        /*
         * A panel is somebody's production web server and this is the least
         * urgent thing asking it for anything. Three requests a run, once a
         * day, one at a time.
         */
        return new RateLimits(perMinute: 20, concurrency: 1, batchSize: 0);
    }

    public function health(): AdapterHealth
    {
        try {
            $response = $this->request()->get('/modules/wp-toolkit/api/v1/instances');
        } catch (Throwable) {
            return AdapterHealth::failing('The panel could not be reached.');
        }

        if ($response->status() === 401 || $response->status() === 403) {
            return AdapterHealth::failing('The panel refused the credential.');
        }

        if ($response->status() === 404) {
            return AdapterHealth::failing('The panel has no WP Toolkit installed.');
        }

        if (! $response->successful()) {
            return AdapterHealth::failing('The panel answered '.$response->status().'.');
        }

        return new AdapterHealth(
            HealthState::Ok,
            'The panel answered.',
            checkedAt: CarbonImmutable::now(),
        );
    }

    /**
     * @return list<SiteInstallation>
     */
    public function sites(): array
    {
        $instances = $this->rows('/modules/wp-toolkit/api/v1/instances');

        // Both collections for the whole panel, once. Four hundred sites is
        // four hundred requests the other way round.
        $plugins = $this->grouped('/modules/wp-toolkit/api/v1/plugins', ComponentKind::Plugin);
        $themes = $this->grouped('/modules/wp-toolkit/api/v1/themes', ComponentKind::Theme);

        $sites = [];

        foreach ($instances as $instance) {
            $id = $this->text($instance, 'id');

            if ($id === null) {
                continue;
            }

            $url = $this->text($instance, 'siteUrl')
                ?? $this->text($instance, 'mainDomain')
                ?? $id;

            $sites[] = new SiteInstallation(
                // The toolkit's own id. A URL moves; this does not.
                key: $id,
                url: $url,
                application: 'wordpress',
                version: $this->text($instance, 'version'),
                latestVersion: $this->text($instance, 'availableVersion')
                    ?? $this->text($instance, 'latestVersion'),
                phpVersion: $this->text($instance, 'phpVersion'),
                components: [
                    ...($plugins[$id] ?? []),
                    ...($themes[$id] ?? []),
                ],
                // Configuration, not discovery: the toolkit knows which sites
                // it has and not what this platform calls the machine it runs
                // on. An operator does.
                deviceKey: $this->serverNode,
                path: $this->text($instance, 'wpPath') ?? $this->text($instance, 'path'),
            );
        }

        return $sites;
    }

    /**
     * Every component on the panel, by instance id.
     *
     * @return array<string, list<SiteComponent>>
     */
    private function grouped(string $path, ComponentKind $kind): array
    {
        $grouped = [];

        foreach ($this->rows($path) as $row) {
            $instance = $this->text($row, 'instanceId');
            $slug = $this->text($row, 'slug') ?? $this->text($row, 'name');

            if ($instance === null || $slug === null) {
                continue;
            }

            $grouped[$instance][] = new SiteComponent(
                kind: $kind,
                slug: $slug,
                name: $this->text($row, 'title') ?? $this->text($row, 'displayName') ?? $slug,
                version: $this->text($row, 'version'),
                latestVersion: $this->text($row, 'availableVersion')
                    ?? $this->text($row, 'latestVersion'),
                // Present and switched off is still a directory of PHP on
                // somebody's account, and it is the one nobody updates.
                active: ($row['status'] ?? null) === 'active' || ($row['isActivated'] ?? false) === true,
                vulnerable: $this->vulnerable($row),
                advisory: $this->advisory($row),
            );
        }

        return $grouped;
    }

    /**
     * Whether anything has been published against this component.
     *
     * **Three-valued.** A toolkit with no vulnerability data has no
     * `vulnerabilities` key at all, and the answer there is `null`: reading
     * an absent field as an empty list would hand a whole fleet a clean bill
     * of health that nothing checked.
     */
    private function vulnerable(mixed $row): ?bool
    {
        if (! is_array($row) || ! array_key_exists('vulnerabilities', $row)) {
            return null;
        }

        $found = $row['vulnerabilities'];

        if (! is_array($found)) {
            return null;
        }

        return $found !== [];
    }

    private function advisory(mixed $row): ?string
    {
        if (! is_array($row) || ! is_array($row['vulnerabilities'] ?? null)) {
            return null;
        }

        $first = $row['vulnerabilities'][0] ?? null;

        if (! is_array($first)) {
            return null;
        }

        // A reference, never a copy: an advisory is somebody else's document
        // and it gets corrected.
        return $this->text($first, 'url') ?? $this->text($first, 'id');
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function rows(string $path): array
    {
        try {
            $response = $this->request()->get($path);
        } catch (Throwable) {
            throw SiteInventoryUnavailable::noAnswer($this->key());
        }

        if ($response->status() === 401 || $response->status() === 403) {
            throw SiteInventoryUnavailable::refused($this->key());
        }

        // Not an outage, and it never will be: a panel without the toolkit
        // answers this for ever, and "did not answer" would send somebody to
        // look at the network.
        if ($response->status() === 404) {
            throw SiteInventoryUnavailable::notInstalled($this->key());
        }

        if (! $response->successful()) {
            throw SiteInventoryUnavailable::answered($this->key(), $response->status());
        }

        $body = $response->json();

        // Plesk answers a bare list; cPanel's shim wraps it in `data`.
        $rows = is_array($body) && isset($body['data']) && is_array($body['data'])
            ? $body['data']
            : $body;

        if (! is_array($rows)) {
            throw SiteInventoryUnavailable::unreadable($this->key(), get_debug_type($rows));
        }

        return array_values(array_filter($rows, is_array(...)));
    }

    /**
     * @param  array<string, mixed>|mixed  $row
     */
    private function text(mixed $row, string $key): ?string
    {
        if (! is_array($row)) {
            return null;
        }

        $value = $row[$key] ?? null;

        if (is_int($value) || is_float($value)) {
            $value = (string) $value;
        }

        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    private function request(): PendingRequest
    {
        $request = Http::baseUrl($this->baseUrl)
            ->timeout($this->timeout)
            ->acceptJson()
            ->withOptions(['verify' => $this->verifyTls]);

        $token = ($this->token)();

        // A missing token still makes the request, so the panel's own 401 is
        // what the operator is told rather than a sentence this adapter made
        // up about its own configuration.
        return is_string($token) && $token !== ''
            ? $request->withToken($token)
            : $request;
    }
}
