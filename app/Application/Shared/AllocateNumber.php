<?php

declare(strict_types=1);

namespace App\Application\Shared;

use App\Domain\Shared\NumberResetPeriod;
use App\Infrastructure\Shared\Models\NumberSequence;
use Carbon\CarbonImmutable;
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
 *
 * **The reset happens under the same lock as the increment**, which is the whole
 * reason it is here and not in a scheduled task. A sequence that restarts every
 * year restarts on the first document of the new year, whenever that document
 * happens to be raised; two of them raised in the same second must not both
 * decide they are the one that resets, and the row lock is what makes that true.
 * A task that reset sequences at midnight would instead be a task that has to
 * run, and a reset that did not happen is a duplicate invoice number.
 */
final readonly class AllocateNumber
{
    public function __construct(private ResolveSeller $sellers) {}

    public function handle(string $organizationId, string $key, string $defaultPrefix = '', int $defaultPadding = 6): string
    {
        $organizationId = $this->sellers->forOrganization($organizationId);

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
                'reset_period' => NumberResetPeriod::Never->value,
            ]);
        }

        $periodKey = $sequence->reset_period->keyFor(CarbonImmutable::now());

        // Null first, not `(string) $periodKey`: a seller who switches the reset
        // back off has a stale-looking key on the row and must not have their
        // numbering restart because of it.
        $stale = $periodKey !== null && $sequence->isStale($periodKey);

        $value = $stale ? 1 : $sequence->next_value;

        // An increment in SQL rather than a read-modify-write, so the row
        // lock is the only thing standing between two callers. The period is
        // written on every allocation, which is what lets a sequence that has
        // never been reset adopt the current period instead of restarting: a
        // seller who turns the yearly reset on in March must not have their
        // March invoices renumbered from one.
        DB::table($sequence->getTable())
            ->where('id', $sequence->getKey())
            ->update([
                'next_value' => $value + 1,
                'period_key' => $periodKey,
                'updated_at' => now(),
            ]);

        return $sequence->format($value);
    }
}
