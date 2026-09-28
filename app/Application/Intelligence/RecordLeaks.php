<?php

declare(strict_types=1);

namespace App\Application\Intelligence;

use App\Domain\Intelligence\Leak;
use App\Infrastructure\Intelligence\Models\FindingDismissal;
use App\Infrastructure\Intelligence\Models\LeakageFinding;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;

/**
 * Raises what is still leaking, clears what somebody has invoiced (§21).
 *
 * The fourth place in this product with the raise-and-clear shape, and the
 * rule is the one the other three learned: **clearing is the half people
 * forget.** A leakage list that only ever grew would be full of invoices
 * somebody raised in March, and an operator who has learned the list is
 * stale stops reading it — which takes the real leak with it.
 *
 * **A finding is kept once cleared**, because "we fixed that, when?" is the
 * question asked the next time the figure moves.
 *
 * **The amount is refreshed on every sweep.** A price rises, a cycle
 * changes, a domain's renewal is repriced by the registrar — what is at
 * stake is a fact about today, not about the night the finding opened. The
 * *clock* is not refreshed: `first_seen_at` is how long this has been true,
 * which is the number that decides whether anybody acts.
 */
final readonly class RecordLeaks
{
    /**
     * @param  list<Leak>  $leaks
     * @return array{raised: int, kept: int, cleared: int, dismissed: int}
     */
    public function handle(
        string $organizationId,
        array $leaks,
        ?CarbonImmutable $at = null,
    ): array {
        $at ??= CarbonImmutable::now();

        $dismissals = $this->dismissals($organizationId, $at);

        $raised = 0;
        $kept = 0;
        $dismissed = 0;
        /** @var list<string> $seen */
        $seen = [];

        foreach ($leaks as $leak) {
            $key = $leak->kind->value.'|'.$leak->subjectId;

            if (isset($dismissals[$key])) {
                $dismissed++;

                continue;
            }

            $seen[] = $key;

            $this->record($organizationId, $leak, $at) ? $raised++ : $kept++;
        }

        return [
            'raised' => $raised,
            'kept' => $kept,
            'cleared' => $this->clearDeparted($organizationId, $seen, $at),
            'dismissed' => $dismissed,
        ];
    }

    /**
     * Open it, or say it is still true. True when this was the first time.
     */
    private function record(string $organizationId, Leak $leak, CarbonImmutable $at): bool
    {
        $existing = LeakageFinding::query()
            ->where('organization_id', $organizationId)
            ->where('kind', $leak->kind->value)
            ->where('subject_id', $leak->subjectId)
            ->whereNull('cleared_at')
            ->first();

        if ($existing instanceof LeakageFinding) {
            $existing->last_seen_at = $at;
            // What is at stake is a fact about today. How long it has been
            // true is `first_seen_at`, and that does not move.
            $existing->amount_minor = $leak->amount->minorUnits;
            $existing->currency_code = $leak->amount->currency->code;
            $existing->subject_label = $leak->label;
            $existing->customer_label = $leak->customerLabel;
            $existing->detail = $leak->detail === [] ? null : $leak->detail;
            $existing->save();

            return false;
        }

        try {
            LeakageFinding::query()->create([
                'organization_id' => $organizationId,
                'kind' => $leak->kind,
                'subject_type' => $leak->subjectType,
                'subject_id' => $leak->subjectId,
                'subject_label' => $leak->label,
                'customer_id' => $leak->customerId,
                'customer_label' => $leak->customerLabel,
                'currency_code' => $leak->amount->currency->code,
                'amount_minor' => $leak->amount->minorUnits,
                'detail' => $leak->detail === [] ? null : $leak->detail,
                'first_seen_at' => $at,
                'last_seen_at' => $at,
            ]);
        } catch (QueryException) {
            // Two sweeps at once, which the unique index refuses. Tested at
            // the guard rather than by racing threads.
            return false;
        }

        return true;
    }

    /**
     * Close what has stopped being true.
     *
     * @param  list<string>  $seen
     */
    private function clearDeparted(string $organizationId, array $seen, CarbonImmutable $at): int
    {
        $cleared = 0;

        foreach (
            LeakageFinding::query()
                ->where('organization_id', $organizationId)
                ->open()
                ->get() as $finding
        ) {
            if (in_array($finding->kind->value.'|'.$finding->subject_id, $seen, strict: true)) {
                continue;
            }

            $finding->cleared_at = $at;
            // The row's own id, because null never collides with null in a
            // unique index and the key would then not be doing its work.
            $finding->cleared_token = $finding->id;
            $finding->save();

            $cleared++;
        }

        return $cleared;
    }

    /**
     * What an operator has said is deliberate.
     *
     * The same table a reconciliation dismissal lives in — the act is
     * identical and `source` is what says which family. A charity given free
     * hosting and a machine somebody built by hand are the same sentence
     * said about two different things.
     *
     * @return array<string, true>
     */
    private function dismissals(string $organizationId, CarbonImmutable $at): array
    {
        $keys = [];

        foreach (
            FindingDismissal::query()
                ->where('organization_id', $organizationId)
                ->where('source', DetectLeakage::Resource)
                ->get() as $dismissal
        ) {
            if (! $dismissal->applies($at)) {
                continue;
            }

            $keys[$dismissal->resource.'|'.($dismissal->subject_id ?? '')] = true;
        }

        return $keys;
    }
}
