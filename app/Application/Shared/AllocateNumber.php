<?php

declare(strict_types=1);

namespace App\Application\Shared;

use App\Infrastructure\Organizations\Models\Organization;
use App\Infrastructure\Shared\Models\NumberSequence;
use Illuminate\Support\Facades\DB;

/**
 * Hands out the next human-readable number for a kind of document.
 *
 * A locked row rather than `max(number) + 1`: two orders placed in the same
 * second have to get different numbers, and a count cannot promise that
 * under any amount of concurrency.
 *
 * Numbers belong to the seller, not the buyer. A customer is an
 * organization of its own in this platform, so allocating against the
 * organization that owns the document would give every customer their own
 * ORD-000001. The seller is resolved here rather than at the three call
 * sites, because a rule that has to be remembered is a rule that will
 * eventually be forgotten.
 *
 * The caller is expected to already be inside the transaction that writes
 * the document. That is deliberate — the number and the thing it names
 * either both exist or neither does, and a number handed out to a
 * transaction that then rolls back leaves a gap nobody can explain to an
 * auditor.
 */
final readonly class AllocateNumber
{
    public function handle(string $organizationId, string $key, string $defaultPrefix = '', int $defaultPadding = 6): string
    {
        $organizationId = $this->sellerFor($organizationId);

        $sequence = NumberSequence::query()
            ->withoutGlobalScope('organization')
            ->where('organization_id', $organizationId)
            ->where('key', $key)
            ->lockForUpdate()
            ->first();

        if (! $sequence instanceof NumberSequence) {
            $sequence = NumberSequence::query()->create([
                'organization_id' => $organizationId,
                'key' => $key,
                'prefix' => $defaultPrefix,
                'next_value' => 1,
                'padding' => $defaultPadding,
            ]);
        }

        $value = $sequence->next_value;

        // An increment in SQL rather than a read-modify-write, so the row
        // lock is the only thing standing between two callers.
        DB::table($sequence->getTable())
            ->where('id', $sequence->getKey())
            ->update(['next_value' => $value + 1, 'updated_at' => now()]);

        return $sequence->format($value);
    }

    /**
     * Who is selling. Usually the provider; a reseller for its own
     * customers, once Phase 11 gives them their own billing.
     */
    private function sellerFor(string $organizationId): string
    {
        $organization = Organization::query()
            ->withoutGlobalScope('organization')
            ->find($organizationId);

        return $organization instanceof Organization
            ? $organization->sellerId()
            : $organizationId;
    }
}
