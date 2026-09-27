<?php

declare(strict_types=1);

namespace App\Application\Security;

use App\Domain\Security\ZoneCheck;
use App\Infrastructure\Domains\Models\Domain;
use App\Infrastructure\Security\Models\ZoneFinding;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;

/**
 * Raises what is wrong with a zone and clears what has been fixed (§8).
 *
 * **Clearing is the half people forget**, exactly as it is for alerts. A
 * findings list that only ever grew would fill with things somebody fixed in
 * March, and an operator who has learned the list is stale is an operator who
 * does not read it — which takes the `+all` with it.
 *
 * **A finding is kept once cleared.** "When did we fix that" is a question
 * somebody asks, and a delete is the one edit that cannot be undone.
 *
 * The severity comes from the check rather than from a column an operator
 * edits: whether two SPF records are a problem is RFC 7208, not a preference.
 * What *is* the operator's is whether they are told about it, which is an
 * alert rule.
 */
final readonly class RecordZoneFindings
{
    /**
     * @param  list<ZoneCheck>  $found
     * @return array{raised: int, kept: int, cleared: int}
     */
    public function handle(Domain $domain, string $source, array $found, ?CarbonImmutable $at = null): array
    {
        $at ??= CarbonImmutable::now();

        $raised = 0;
        $kept = 0;

        foreach ($found as $check) {
            $this->record($domain, $source, $check, $at) ? $raised++ : $kept++;
        }

        return [
            'raised' => $raised,
            'kept' => $kept,
            'cleared' => $this->clearDeparted($domain, $source, $found, $at),
        ];
    }

    /**
     * Open it, or say it is still true. True when this was the first time.
     */
    private function record(Domain $domain, string $source, ZoneCheck $check, CarbonImmutable $at): bool
    {
        $existing = ZoneFinding::query()
            ->where('domain_id', $domain->id)
            ->where('check', $check->value)
            ->where('source', $source)
            ->whereNull('cleared_at')
            ->first();

        if ($existing instanceof ZoneFinding) {
            $existing->last_seen_at = $at;
            $existing->save();

            return false;
        }

        try {
            ZoneFinding::query()->create([
                'organization_id' => $domain->organization_id,
                'domain_id' => $domain->id,
                'check' => $check,
                'severity' => $check->severity(),
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
     * @param  list<ZoneCheck>  $found
     */
    private function clearDeparted(Domain $domain, string $source, array $found, CarbonImmutable $at): int
    {
        $stillTrue = array_map(static fn (ZoneCheck $check): string => $check->value, $found);

        $open = ZoneFinding::query()
            ->where('domain_id', $domain->id)
            ->where('source', $source)
            ->whereNull('cleared_at')
            ->when($stillTrue !== [], static fn ($query) => $query->whereNotIn('check', $stillTrue))
            ->get();

        foreach ($open as $finding) {
            $finding->cleared_at = $at;
            // The token that lets the same check be raised again. Empty means
            // open, and a unique index over nulls would not collide.
            $finding->cleared_token = $finding->id;
            $finding->save();
        }

        return $open->count();
    }
}
