<?php

declare(strict_types=1);

namespace App\Application\Automation\Runs;

use App\Domain\Automation\Contracts\AutomationRun;
use App\Domain\Automation\ItemOutcome;
use App\Domain\Automation\RunItem;
use App\Domain\Automation\RunSummary;
use App\Infrastructure\Security\Models\AbuseEvidence;
use App\Support\Logging\SecretRedactor;
use App\Support\Organizations\OrganizationContext;
use Throwable;

/**
 * Deletes evidence that is past its deadline (§13).
 *
 * **The first task in this product whose job is to forget.** Everything else
 * here is append-only on principle — the ledger, the graph's edges, the audit
 * log, an incident's timeline — and this is the deliberate exception: an
 * abuse complaint holds a third party's data, and keeping it for ever is a
 * privacy decision nobody made.
 *
 * It asks a question about **rows** rather than about the clock (ADR 0031):
 * "what is past its `retain_until`". So a scheduler that was down for a week
 * catches up on the next pass rather than leaving a fortnight of somebody's
 * data behind permanently — which for a deletion is the failure that matters.
 *
 * **What is deleted is the reference, never the case.** The decision the desk
 * made, the timeline it wrote and the action it took all stay: those are the
 * platform's own record of its own conduct, and a complaint nobody can
 * evidence any more is still a complaint that was answered. The audit row for
 * the capture stays too, and deliberately carries the kind and the retention
 * rather than the reference itself — an audit log is the one table nothing
 * deletes from, so putting the third party's data in it would have defeated
 * the whole arrangement.
 */
final readonly class ForgetAbuseEvidence implements AutomationRun
{
    public function __construct(
        private OrganizationContext $organizations,
        private SecretRedactor $redactor,
    ) {}

    public function handle(): RunSummary
    {
        return $this->organizations->withoutBoundary(function (): RunSummary {
            $summary = new RunSummary;

            // Consumed inside the callback: a builder handed back out of it
            // would be scoped again by the time anything ran it.
            foreach (AbuseEvidence::query()->expired()->cursor() as $evidence) {
                $summary = $summary->examining();

                try {
                    $caseId = $evidence->abuse_case_id;
                    $kind = $evidence->kind->value;

                    $evidence->delete();

                    $summary = $summary->changing(new RunItem(
                        ItemOutcome::Changed,
                        AbuseEvidence::class,
                        $caseId,
                        // The case's id and the kind, never the reference:
                        // a run record is read by people and kept for a long
                        // time, which is the shape this row exists to avoid.
                        $kind,
                        null,
                    ));
                } catch (Throwable $exception) {
                    $summary = $summary->failing(new RunItem(
                        ItemOutcome::Failed,
                        AbuseEvidence::class,
                        $evidence->id,
                        $evidence->kind->value,
                        $this->redactor->redactString($exception->getMessage()),
                    ));
                }
            }

            return $summary;
        });
    }
}
