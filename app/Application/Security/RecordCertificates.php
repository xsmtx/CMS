<?php

declare(strict_types=1);

namespace App\Application\Security;

use App\Domain\Infrastructure\Certificates\DeployedCertificate;
use App\Infrastructure\Domains\Models\Domain;
use App\Infrastructure\Resources\Models\ResourceNode;
use App\Infrastructure\Security\Models\Certificate;
use Carbon\CarbonImmutable;

/**
 * Writes down what a certificate adapter found (§8).
 *
 * **A sweep replaces what this source reported last time.** A certificate the
 * adapter stops naming has been replaced or removed, so its row is
 * **retired** rather than deleted — the same decision the graph made about
 * nodes, and for the same reason: "what was on that machine in March" is a
 * question somebody asks after an outage, and a delete is the one edit that
 * cannot be undone.
 *
 * **It retires only what it wrote**, scoped by source. Retiring by
 * organization would take a second adapter's certificates with it every time
 * this one ran — the rule `DiscoverTopology` states in as many words.
 *
 * **Attribution is by name**, and only where the name is unambiguous. A
 * certificate for `shop.example.com` belongs to whoever holds `example.com`
 * here; a wildcard covering four customers' subdomains belongs to none of
 * them, and saying so is better than picking the first. The link is a
 * convenience for the screen, never a claim of ownership — the certificate
 * belongs to whoever deployed it.
 */
final readonly class RecordCertificates
{
    /**
     * @param  list<DeployedCertificate>  $found
     * @return array{recorded: int, retired: int}
     */
    public function handle(string $organizationId, string $source, array $found): array
    {
        $at = CarbonImmutable::now();
        $seen = [];

        foreach ($found as $certificate) {
            $this->record($organizationId, $source, $certificate, $at);
            $seen[] = $certificate->fingerprint;
        }

        return [
            'recorded' => count($found),
            'retired' => $this->retireDeparted($organizationId, $source, $seen, $at),
        ];
    }

    private function record(
        string $organizationId,
        string $source,
        DeployedCertificate $certificate,
        CarbonImmutable $at,
    ): void {
        $domain = $this->domainFor($certificate);

        Certificate::query()->updateOrCreate(
            ['source' => $source, 'fingerprint' => $certificate->fingerprint],
            [
                'organization_id' => $organizationId,
                'common_name' => $certificate->commonName,
                'subject_alternative_names' => $certificate->names(),
                'issuer' => $certificate->issuer,
                'serial' => $certificate->serial,
                // Straight from the certificate. Never computed, never
                // defaulted: an expiry this platform guessed would be worse
                // than none, because somebody would act on it.
                'not_before' => $certificate->notBefore,
                'not_after' => $certificate->notAfter,
                'chain_ok' => $certificate->chainOk,
                'resource_node_id' => $this->nodeFor($organizationId, $certificate)?->id,
                'domain_id' => $domain?->id,
                // No service: a domain is not a service (ADR 0028) and does
                // not carry one. A certificate's link to a service, when it
                // has one, comes from the node it was found on rather than
                // from the name it covers.
                'customer_id' => $domain?->customer_id,
                'discovered_at' => $at,
                // Seen again, so it is no longer retired. A certificate that
                // comes back is the ordinary case after a failed deploy is
                // rolled back.
                'retired_at' => null,
            ],
        );
    }

    /**
     * The domain this is for, when exactly one is ours.
     *
     * A certificate naming `shop.example.com` belongs to whoever holds
     * `example.com`; one naming four customers' subdomains belongs to none of
     * them. Ambiguity is refused rather than resolved — the rule
     * `RecordSamples::byHostname()` states, because a certificate attached to
     * the wrong customer is worse than one attached to nobody.
     */
    private function domainFor(DeployedCertificate $certificate): ?Domain
    {
        $candidates = [];

        foreach ($certificate->names() as $name) {
            $bare = str_starts_with($name, '*.') ? substr($name, 2) : $name;

            foreach ($this->parents($bare) as $candidate) {
                $domain = Domain::query()
                    ->whereRaw('LOWER(name) = ?', [$candidate])
                    ->first();

                if ($domain instanceof Domain) {
                    $candidates[$domain->id] = $domain;

                    break;
                }
            }
        }

        return count($candidates) === 1 ? reset($candidates) : null;
    }

    /**
     * A hostname and the names it sits under, longest first.
     *
     * `shop.eu.example.com` tries itself, then `eu.example.com`, then
     * `example.com` — the nearest match wins, so a customer who holds both
     * `eu.example.com` and `example.com` gets the specific one.
     *
     * @return list<string>
     */
    private function parents(string $hostname): array
    {
        $labels = explode('.', $hostname);
        $names = [];

        // Two labels is the shortest thing worth trying: a single label is a
        // hostname on somebody's network, not a domain anybody registers.
        for ($i = 0; $i <= count($labels) - 2; $i++) {
            $names[] = implode('.', array_slice($labels, $i));
        }

        return $names;
    }

    private function nodeFor(string $organizationId, DeployedCertificate $certificate): ?ResourceNode
    {
        if ($certificate->nodeKey === null) {
            return null;
        }

        return ResourceNode::query()
            ->where('organization_id', $organizationId)
            ->where('node_key', $certificate->nodeKey)
            ->whereNull('retired_at')
            ->first();
    }

    /**
     * @param  list<string>  $seen
     */
    private function retireDeparted(
        string $organizationId,
        string $source,
        array $seen,
        CarbonImmutable $at,
    ): int {
        return Certificate::query()
            ->where('organization_id', $organizationId)
            ->where('source', $source)
            ->whereNull('retired_at')
            ->when($seen !== [], static fn ($query) => $query->whereNotIn('fingerprint', $seen))
            ->update(['retired_at' => $at]);
    }
}
