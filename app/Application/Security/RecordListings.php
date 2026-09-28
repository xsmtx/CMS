<?php

declare(strict_types=1);

namespace App\Application\Security;

use App\Domain\Infrastructure\Mail\BlocklistListing;
use App\Domain\Network\IpAddress;
use App\Infrastructure\Security\Models\ReputationListing;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use InvalidArgumentException;

/**
 * Raises what is listed and clears what has been lifted (§13).
 *
 * **Clearing is the half people forget**, exactly as it is for alerts and for
 * zone findings. A reputation screen that only ever grew would be full of
 * listings somebody got lifted in March, and an operator who has learned the
 * list is stale stops reading it — which takes the real listing with it.
 *
 * **A listing is kept once cleared.** "How long were we on that list" is the
 * question somebody asks afterwards, usually while writing to a customer, and
 * a delete is the one edit that cannot be undone.
 *
 * **Attribution happens once, when the listing is first seen.** It is the
 * same walk `AttributeReport` does for an abuse report and a DDoS event, and
 * the moment matters for the same reason: an address handed to somebody else
 * since Tuesday must not make Tuesday's listing theirs. Re-attributing on
 * every sweep would do exactly that.
 *
 * **An address that will not parse is counted and dropped**, not stored as
 * text. A row whose address cannot be compared is a row nothing can attribute
 * and nothing can clear — the same reasoning that keeps a metric core has no
 * `MetricKind` for out of the telemetry table.
 */
final readonly class RecordListings
{
    public function __construct(private AttributeReport $attribution) {}

    /**
     * @param  list<BlocklistListing>  $listings
     * @return array{raised: int, kept: int, cleared: int, skipped: int}
     */
    public function handle(string $organizationId, string $source, array $listings, ?CarbonImmutable $at = null): array
    {
        $at ??= CarbonImmutable::now();

        $raised = 0;
        $kept = 0;
        $skipped = 0;
        /** @var list<array{bytes: string, list: string}> $seen */
        $seen = [];

        foreach ($listings as $listing) {
            $address = $this->parse($listing->address);

            if (! $address instanceof IpAddress) {
                $skipped++;

                continue;
            }

            $seen[] = ['bytes' => $address->bytes, 'list' => $listing->list];

            $this->record($organizationId, $source, $address, $listing, $at) ? $raised++ : $kept++;
        }

        return [
            'raised' => $raised,
            'kept' => $kept,
            'cleared' => $this->clearDeparted($organizationId, $source, $seen, $at),
            'skipped' => $skipped,
        ];
    }

    /**
     * Open it, or say it is still true. True when this was the first time.
     *
     * The reason and the delisting link are refreshed on every sweep, because
     * a list changes its own words and its own form URL, and the one somebody
     * follows should be the one the list currently publishes.
     */
    private function record(
        string $organizationId,
        string $source,
        IpAddress $address,
        BlocklistListing $listing,
        CarbonImmutable $at,
    ): bool {
        $existing = ReputationListing::query()
            ->where('organization_id', $organizationId)
            ->where('address_bytes', $address->bytes)
            ->where('list', $listing->list)
            ->where('source', $source)
            ->whereNull('cleared_at')
            ->first();

        if ($existing instanceof ReputationListing) {
            $existing->last_seen_at = $at;
            $existing->reason = $listing->reason;
            $existing->delist_url = $listing->delistUrl;
            $existing->save();

            return false;
        }

        $held = $this->attribution->atAddress($organizationId, $address->text(), $at);

        try {
            ReputationListing::query()->create([
                'organization_id' => $organizationId,
                'address' => $address->text(),
                'address_bytes' => $address->bytes,
                'list' => $listing->list,
                'reason' => $listing->reason,
                'delist_url' => $listing->delistUrl,
                'customer_id' => $held->customerId,
                'service_id' => $held->serviceId,
                'source' => $source,
                'first_seen_at' => $at,
                'last_seen_at' => $at,
            ]);
        } catch (QueryException) {
            // Two sweeps at once, which the unique index refuses. Tested at
            // the guard rather than by racing threads: a flaky test is worse
            // than none, because it gets retried until it passes.
            return false;
        }

        return true;
    }

    /**
     * Close what has stopped being true.
     *
     * @param  list<array{bytes: string, list: string}>  $seen
     */
    private function clearDeparted(string $organizationId, string $source, array $seen, CarbonImmutable $at): int
    {
        $stillListed = [];

        foreach ($seen as $pair) {
            $stillListed[bin2hex($pair['bytes']).'|'.$pair['list']] = true;
        }

        $open = ReputationListing::query()
            ->where('organization_id', $organizationId)
            ->where('source', $source)
            ->whereNull('cleared_at')
            ->get();

        $cleared = 0;

        foreach ($open as $row) {
            if (isset($stillListed[bin2hex($row->address_bytes).'|'.$row->list])) {
                continue;
            }

            $row->cleared_at = $at;
            // The token that lets the same address be listed again. Empty
            // means open, and a unique index over nulls would not collide.
            $row->cleared_token = $row->id;
            $row->save();

            $cleared++;
        }

        return $cleared;
    }

    /**
     * A value object or nothing. A blocklist's own typo is not an exception.
     */
    private function parse(string $value): ?IpAddress
    {
        try {
            return IpAddress::parse($value);
        } catch (InvalidArgumentException) {
            return null;
        }
    }
}
