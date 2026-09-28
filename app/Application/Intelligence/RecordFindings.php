<?php

declare(strict_types=1);

namespace App\Application\Intelligence;

use App\Domain\Intelligence\Difference;
use App\Infrastructure\Intelligence\Models\FindingDismissal;
use App\Infrastructure\Intelligence\Models\ReconciliationFinding;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;

/**
 * Raises what is still true, clears what has been fixed (§22).
 *
 * **Clearing is the half people forget**, exactly as it is for alerts, zone
 * findings and blocklist listings. A reconciliation queue that only ever grew
 * would be full of accounts somebody put right in March, and an operator who
 * has learned the list is stale stops reading it — which takes the real
 * finding with it.
 *
 * **A finding is kept once cleared.** "How long was that account suspended
 * without us knowing" is the question asked afterwards, usually while writing
 * to a customer, and a delete is the one edit that cannot be undone.
 *
 * **A dismissal suppresses the row rather than the comparison.** The sweep
 * still asks, still concludes, and still clears what has been fixed; what a
 * dismissal changes is whether an operator is shown it. Skipping the
 * comparison instead would mean a machine somebody deliberately built by
 * hand could then change under them and nothing would say so.
 *
 * **Nothing here acts.** ADR 0031 and ADR 0032 applied to a comparison: a
 * sweep that quietly fixed what it found would suspend a customer because a
 * panel was slow to answer, and the audit row afterwards would say the
 * platform did it to itself.
 */
final readonly class RecordFindings
{
    /**
     * @param  list<Difference>  $differences
     * @return array{raised: int, kept: int, cleared: int, dismissed: int}
     */
    public function handle(
        string $organizationId,
        string $source,
        array $differences,
        ?CarbonImmutable $at = null,
    ): array {
        $at ??= CarbonImmutable::now();

        $dismissals = $this->dismissals($organizationId, $source, $at);

        $raised = 0;
        $kept = 0;
        $dismissed = 0;
        /** @var list<string> $seen */
        $seen = [];

        foreach ($differences as $difference) {
            if (! $difference->class->isFinding()) {
                continue;
            }

            if (isset($dismissals[$difference->key()])) {
                $dismissed++;

                continue;
            }

            $seen[] = $difference->key();

            $this->record($organizationId, $source, $difference, $at) ? $raised++ : $kept++;
        }

        return [
            'raised' => $raised,
            'kept' => $kept,
            'cleared' => $this->clearDeparted($organizationId, $source, $seen, $at),
            'dismissed' => $dismissed,
        ];
    }

    /**
     * Open it, or say it is still true. True when this was the first time.
     *
     * The words each side used are refreshed on every sweep, because a
     * provider changes its own vocabulary and the sentence an operator reads
     * should be the one it is using now. The **class** is refreshed too: a
     * drift that became a missing account is the same finding getting worse,
     * not a new one — and closing and reopening it would reset the clock that
     * says how long it has been wrong.
     */
    private function record(
        string $organizationId,
        string $source,
        Difference $difference,
        CarbonImmutable $at,
    ): bool {
        $existing = ReconciliationFinding::query()
            ->where('organization_id', $organizationId)
            ->where('source', $source)
            ->where('resource', $difference->resource)
            ->where('subject_id', $difference->subjectId)
            ->where('remote_key', $difference->remoteKey)
            ->where('field', $difference->field)
            ->whereNull('cleared_at')
            ->first();

        if ($existing instanceof ReconciliationFinding) {
            $existing->last_seen_at = $at;
            $existing->class = $difference->class;
            $existing->subject_label = $difference->label;
            $existing->expected = $difference->expected;
            $existing->found = $difference->found;
            $existing->detail = $difference->detail === [] ? null : $difference->detail;
            $existing->save();

            return false;
        }

        try {
            ReconciliationFinding::query()->create([
                'organization_id' => $organizationId,
                'subject_type' => $difference->subjectType,
                'subject_id' => $difference->subjectId,
                'subject_label' => $difference->label,
                'remote_key' => $difference->remoteKey,
                'resource' => $difference->resource,
                'class' => $difference->class,
                'field' => $difference->field,
                'expected' => $difference->expected,
                'found' => $difference->found,
                'detail' => $difference->detail === [] ? null : $difference->detail,
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
     * @param  list<string>  $seen
     */
    private function clearDeparted(
        string $organizationId,
        string $source,
        array $seen,
        CarbonImmutable $at,
    ): int {
        $open = ReconciliationFinding::query()
            ->where('organization_id', $organizationId)
            ->where('source', $source)
            ->whereNull('cleared_at')
            ->get();

        $cleared = 0;

        foreach ($open as $finding) {
            $key = implode('|', [
                $finding->resource,
                $finding->subject_id ?? '',
                $finding->remote_key ?? '',
                $finding->field ?? '',
            ]);

            if (in_array($key, $seen, strict: true)) {
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
     * What an operator has said is deliberate, keyed the way a finding is.
     *
     * A dismissal whose window has passed simply does not appear here, which
     * is ADR 0031 applied to somebody's judgement: the finding comes back by
     * itself, and no scheduled task has to run for the queue to be honest.
     *
     * @return array<string, true>
     */
    private function dismissals(string $organizationId, string $source, CarbonImmutable $at): array
    {
        $keys = [];

        foreach (
            FindingDismissal::query()
                ->where('organization_id', $organizationId)
                ->where('source', $source)
                ->get() as $dismissal
        ) {
            if (! $dismissal->applies($at)) {
                continue;
            }

            $keys[implode('|', [
                $dismissal->resource,
                $dismissal->subject_id ?? '',
                $dismissal->remote_key ?? '',
                $dismissal->field ?? '',
            ])] = true;
        }

        return $keys;
    }
}
