<?php

declare(strict_types=1);

namespace App\Application\Infrastructure;

use App\Domain\Infrastructure\Backup\ProtectedResource;
use App\Infrastructure\Backup\Models\BackupProtection;
use App\Infrastructure\Provisioning\Models\Service;
use App\Support\Organizations\OrganizationSubtree;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Writes down what a backup source says it is protecting (§12).
 *
 * **A sweep replaces what this source reported last time.** A resource the
 * adapter stops naming has been taken out of the backup job, so its row is
 * **retired** rather than deleted: the date something stopped being protected
 * is the only thing anybody wants afterwards, and a delete is the one edit
 * that cannot be undone. Certificates and graph nodes made the same choice.
 *
 * **It retires only what it wrote**, scoped by source. Retiring by
 * organization would take a second source's rows with it every time this one
 * ran, which is the rule `DiscoverTopology` and `RecordCertificates` both
 * state — and during a migration from one backup vendor to another, having
 * both is the entire point.
 *
 * **Attribution is by name and ambiguity is refused.** A resource named
 * `example.com` is matched against a service's domain and then its name,
 * lower-cased; two services answering to one name match nothing at all. That
 * is `RecordSamples::byHostname()`'s rule applied again, for the same reason:
 * a protection attached to the wrong service is worse than one nobody placed,
 * because the first is what somebody reads before telling a customer their
 * site is backed up.
 *
 * **An unmatched row is kept.** It is either a backup job for a customer who
 * left — worth knowing, and worth money — or a name this platform spells
 * differently. Both are findings and neither is a reason to drop the row.
 */
final readonly class RecordProtections
{
    public function __construct(private OrganizationSubtree $subtree) {}

    /**
     * @param  list<ProtectedResource>  $found
     * @return array{recorded: int, retired: int, matched: int}
     */
    public function handle(string $organizationId, string $source, array $found, ?CarbonImmutable $at = null): array
    {
        $at ??= CarbonImmutable::now();

        $services = $this->servicesByName($organizationId, $found);
        $seen = [];
        $matched = 0;

        foreach ($found as $resource) {
            $service = $services[mb_strtolower(trim($resource->name))] ?? null;

            if ($service instanceof Service) {
                $matched++;
            }

            $this->record($organizationId, $source, $resource, $service, $at);
            $seen[] = $resource->key;
        }

        return [
            'recorded' => count($found),
            'retired' => $this->retireDeparted($organizationId, $source, $seen, $at),
            'matched' => $matched,
        ];
    }

    private function record(
        string $organizationId,
        string $source,
        ProtectedResource $resource,
        ?Service $service,
        CarbonImmutable $at,
    ): void {
        $existing = BackupProtection::query()
            ->withoutGlobalScope('organization')
            ->where('source', $source)
            ->where('resource_key', $resource->key)
            ->first();

        $attributes = [
            'resource_name' => $resource->name,
            'resource_type' => $resource->resourceType,
            'repository' => $resource->repository,
            'last_outcome' => $resource->lastOutcome,
            'last_run_at' => $resource->lastRunAt,
            'restore_points' => $resource->restorePoints,
            'size_bytes' => $resource->sizeBytes,
            'service_id' => $service?->id,
            'customer_id' => $service?->customer_id,
            'last_seen_at' => $at,
            // A resource that had left the job and is back is protected
            // again, and a retired row that stayed retired would read as a
            // gap that never closed.
            'retired_at' => null,
        ];

        /*
         * The last good copy only ever moves forward.
         *
         * A source that reports null for a run it could not describe must not
         * erase what it said yesterday: "there has never been a good copy"
         * and "I cannot tell you about the last one" are different answers,
         * and writing the first when it meant the second makes a protected
         * service look abandoned.
         */
        if ($resource->lastGoodAt instanceof CarbonImmutable) {
            $attributes['last_good_at'] = $resource->lastGoodAt;
        }

        if ($existing instanceof BackupProtection) {
            $existing->fill($attributes)->save();

            return;
        }

        BackupProtection::query()->create([
            ...$attributes,
            'organization_id' => $organizationId,
            'source' => $source,
            'resource_key' => $resource->key,
            'last_good_at' => $resource->lastGoodAt,
            'first_seen_at' => $at,
        ]);
    }

    /**
     * Close what this source has stopped naming.
     *
     * @param  list<string>  $seen
     */
    private function retireDeparted(string $organizationId, string $source, array $seen, CarbonImmutable $at): int
    {
        return BackupProtection::query()
            ->withoutGlobalScope('organization')
            ->where('organization_id', $organizationId)
            ->where('source', $source)
            ->whereNull('retired_at')
            ->when($seen !== [], static fn ($query) => $query->whereNotIn('resource_key', $seen))
            ->update(['retired_at' => $at]);
    }

    /**
     * The services these resources might be, indexed by every name they
     * answer to — and with the ambiguous ones removed.
     *
     * One query rather than one per resource: a source with four hundred
     * accounts would otherwise be four hundred round trips per sweep.
     *
     * @param  list<ProtectedResource>  $found
     * @return array<string, Service>
     */
    private function servicesByName(string $organizationId, array $found): array
    {
        $names = array_values(array_unique(array_map(
            static fn (ProtectedResource $resource): string => mb_strtolower(trim($resource->name)),
            $found,
        )));

        if ($names === []) {
            return [];
        }

        $services = Service::query()
            ->withoutGlobalScope('organization')
            ->whereIn('organization_id', $this->subtree->ids($organizationId))
            ->where(static fn ($query) => $query
                ->whereIn(DB::raw('lower(domain)'), $names)
                ->orWhereIn(DB::raw('lower(name)'), $names))
            ->get();

        /** @var array<string, Service|false> $byName */
        $byName = [];

        foreach ($services as $service) {
            foreach ([$service->domain, $service->name] as $candidate) {
                if (! is_string($candidate) || trim($candidate) === '') {
                    continue;
                }

                $key = mb_strtolower(trim($candidate));

                if (! in_array($key, $names, strict: true)) {
                    continue;
                }

                // Two services answering to one name match nothing: a
                // protection attached to the wrong service is worse than one
                // nobody placed.
                $byName[$key] = array_key_exists($key, $byName) && $byName[$key] !== $service
                    ? false
                    : $service;
            }
        }

        return array_filter($byName, static fn (Service|false $service): bool => $service !== false);
    }
}
