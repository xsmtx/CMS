<?php

declare(strict_types=1);

namespace App\Application\Intelligence;

use App\Domain\Intelligence\Difference;
use App\Domain\Intelligence\ReconciliationClass;
use App\Infrastructure\Network\Models\IpAddressRecord;
use App\Infrastructure\Provisioning\Models\Service;
use App\Infrastructure\Resources\Models\ResourceNode;
use App\Infrastructure\Security\Models\Certificate;

/**
 * What exists out there and belongs to nobody here (§21).
 *
 * Reconciliation read from the other end: `ReconcileServices` starts with a
 * row and asks the provider about it; this starts with what the providers
 * reported and asks whether anything here owns it. A machine built by hand
 * for a migration and never recorded is invisible to the first and is exactly
 * what the second is for.
 *
 * **An orphan is a finding, never a verdict.** Every kind below has a
 * legitimate reason to exist without a row here: a machine built for a
 * migration, an address held for a customer arriving next week, a
 * certificate somebody issued by hand while the automation was broken. So
 * nothing offers a delete, the sweep proposes `Investigate`, and a dismissal
 * is how an operator says "this one is deliberate".
 *
 * **Absence of an adapter is not evidence.** Only what has actually been
 * discovered is considered; a platform with no hypervisor module reports no
 * orphaned machines rather than reporting that every machine is orphaned.
 * That is the same rule as a metric that has gone stale and a component whose
 * advisories nobody read, and it is the reason this class reads the graph
 * rather than a list of kinds it hopes exist.
 *
 * **Where the only link possible is a name, the finding says so.** A
 * certificate can be matched to a domain by its common name and nothing
 * stronger; a machine can be matched to a service by an external id, which is
 * an identity. The two are different strengths of claim and the row carries
 * which one was used, because an operator deciding whether to destroy
 * something deserves to know how sure the platform is.
 */
final readonly class DetectOrphans
{
    public const string Resource = 'orphan';

    private const string MachineKind = 'virtual_machine';

    private const string SiteKind = 'site';

    /**
     * @return list<Difference>
     */
    public function handle(string $organizationId): array
    {
        return [
            ...$this->machines($organizationId),
            ...$this->sites($organizationId),
            ...$this->addresses($organizationId),
            ...$this->certificates($organizationId),
        ];
    }

    /**
     * Machines a hypervisor reports that no service points at.
     *
     * Matched on `services.external_id`, which is an identity rather than a
     * name: the provisioning code writes it the moment the provider returns
     * it, precisely so that an account can be found again.
     *
     * @return list<Difference>
     */
    private function machines(string $organizationId): array
    {
        $known = $this->externalIds($organizationId);
        $differences = [];

        foreach ($this->nodes($organizationId, self::MachineKind) as $node) {
            $attributes = $node->attributes ?? [];
            $key = $attributes['machine_key'] ?? null;

            if (is_string($key) && isset($known[$key])) {
                continue;
            }

            $differences[] = new Difference(
                class: ReconciliationClass::Orphan,
                resource: self::MachineKind,
                label: $node->label,
                remoteKey: $node->node_key,
                field: 'owner',
                expected: null,
                found: is_string($key) ? $key : $node->node_key,
                detail: array_filter([
                    'adapter' => $attributes['adapter'] ?? null,
                    'state' => $attributes['state'] ?? null,
                    // Which link was looked for, so an operator knows how
                    // sure this is.
                    'matched_on' => 'external_id',
                ], static fn (mixed $value): bool => $value !== null),
            );
        }

        return $differences;
    }

    /**
     * Sites a panel reports that no service or domain here accounts for.
     *
     * Matched on the host in the site's URL against a service's domain,
     * which is a **name** rather than an identity — the panel has never
     * heard of this platform's ids. The row says so.
     *
     * @return list<Difference>
     */
    private function sites(string $organizationId): array
    {
        $domains = $this->serviceDomains($organizationId);
        $differences = [];

        foreach ($this->nodes($organizationId, self::SiteKind) as $node) {
            $attributes = $node->attributes ?? [];
            $url = $attributes['url'] ?? $node->label;
            $host = $this->host(is_string($url) ? $url : $node->label);

            if ($host !== null && isset($domains[$host])) {
                continue;
            }

            $differences[] = new Difference(
                class: ReconciliationClass::Orphan,
                resource: self::SiteKind,
                label: $node->label,
                remoteKey: $node->node_key,
                field: 'owner',
                found: $host,
                detail: array_filter([
                    'application' => $attributes['application'] ?? null,
                    'matched_on' => 'domain',
                ], static fn (mixed $value): bool => $value !== null),
            );
        }

        return $differences;
    }

    /**
     * Addresses a device is wearing that this platform's plan does not have.
     *
     * The comparison §5 built `ip_addresses` to make possible: the table is
     * what somebody intends to hand out and the node is what a box says it
     * is actually configured with, and the value is being able to say they
     * disagree.
     *
     * **Matched on bytes, never on text.** `2001:db8::1` and its expanded
     * spelling are one address, and a text comparison would report every
     * IPv6 address on the network as an orphan.
     *
     * @return list<Difference>
     */
    private function addresses(string $organizationId): array
    {
        $planned = $this->plannedAddresses($organizationId);
        $differences = [];

        foreach ($this->nodes($organizationId, 'ip_address') as $node) {
            $attributes = $node->attributes ?? [];
            $bytes = $this->bytes(is_string($attributes['address'] ?? null)
                ? $attributes['address']
                : $node->label);

            if ($bytes === null || isset($planned[$bytes])) {
                continue;
            }

            $differences[] = new Difference(
                class: ReconciliationClass::Orphan,
                resource: 'ip_address',
                label: $node->label,
                remoteKey: $node->node_key,
                field: 'owner',
                found: $node->label,
                detail: ['matched_on' => 'address'],
            );
        }

        return $differences;
    }

    /**
     * Certificates in the fleet that no domain or service here accounts for.
     *
     * A certificate already carries `domain_id` and `service_id` where the
     * collector could place it; one with neither has been deployed on
     * somebody's behalf and nothing here says whose.
     *
     * @return list<Difference>
     */
    private function certificates(string $organizationId): array
    {
        $differences = [];

        foreach (
            Certificate::query()
                ->withoutGlobalScope('organization')
                ->where('organization_id', $organizationId)
                ->whereNull('domain_id')
                ->whereNull('service_id')
                ->live()
                ->limit(500)
                ->get() as $certificate
        ) {
            $differences[] = new Difference(
                class: ReconciliationClass::Orphan,
                resource: 'certificate',
                label: $certificate->common_name,
                // The fingerprint, not the name: one name is served by four
                // certificates over a year.
                remoteKey: $certificate->fingerprint,
                field: 'owner',
                found: $certificate->common_name,
                detail: [
                    'issuer' => $certificate->issuer,
                    'matched_on' => 'domain',
                ],
            );
        }

        return $differences;
    }

    /**
     * @return array<string, true>
     */
    private function externalIds(string $organizationId): array
    {
        $ids = [];

        foreach (
            Service::query()
                ->withoutGlobalScope('organization')
                ->where('organization_id', $organizationId)
                ->whereNotNull('external_id')
                ->pluck('external_id') as $id
        ) {
            if (is_string($id)) {
                $ids[$id] = true;
            }
        }

        return $ids;
    }

    /**
     * @return array<string, true>
     */
    private function serviceDomains(string $organizationId): array
    {
        $domains = [];

        foreach (
            Service::query()
                ->withoutGlobalScope('organization')
                ->where('organization_id', $organizationId)
                ->whereNotNull('domain')
                ->pluck('domain') as $domain
        ) {
            if (is_string($domain)) {
                $domains[mb_strtolower($domain)] = true;
            }
        }

        return $domains;
    }

    /**
     * @return array<string, true>
     */
    private function plannedAddresses(string $organizationId): array
    {
        $addresses = [];

        foreach (
            IpAddressRecord::query()
                ->withoutGlobalScope('organization')
                ->where('organization_id', $organizationId)
                ->pluck('address_bytes') as $bytes
        ) {
            if (is_string($bytes)) {
                $addresses[$bytes] = true;
            }
        }

        return $addresses;
    }

    /**
     * @return list<ResourceNode>
     */
    private function nodes(string $organizationId, string $kind): array
    {
        return array_values(ResourceNode::query()
            ->withoutGlobalScope('organization')
            ->where('organization_id', $organizationId)
            ->where('kind', $kind)
            ->whereNull('retired_at')
            ->limit(2000)
            ->get()
            ->all());
    }

    private function host(string $url): ?string
    {
        $host = parse_url($url, PHP_URL_HOST);

        if (is_string($host) && $host !== '') {
            return mb_strtolower($host);
        }

        // A panel that reported a bare domain rather than a URL. Trimmed to
        // the same shape so the comparison is the same comparison.
        $trimmed = mb_strtolower(trim($url, '/'));

        return $trimmed === '' ? null : $trimmed;
    }

    private function bytes(string $address): ?string
    {
        /*
         * A device reports what it is wearing, which includes the mask:
         * `192.0.2.1/24`. `inet_pton` answers false for that, and a silent
         * false here would drop every address on the network and report no
         * orphans at all — the quietest possible way for this to be wrong.
         */
        $address = strstr($address, '/', before_needle: true) ?: $address;

        $packed = @inet_pton(trim($address));

        if ($packed === false) {
            return null;
        }

        // IPv4 is mapped into the IPv6 space here as it is everywhere else in
        // this product, so one column and one comparison serve both families.
        return strlen($packed) === 4
            ? str_repeat("\0", 10)."\xff\xff".$packed
            : $packed;
    }
}
