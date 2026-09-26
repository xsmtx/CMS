<?php

declare(strict_types=1);

namespace App\Application\Automation\Runs;

use App\Application\Reliability\EvaluateAlertRule;
use App\Domain\Automation\Contracts\AutomationRun;
use App\Domain\Automation\ItemOutcome;
use App\Domain\Automation\RunItem;
use App\Domain\Automation\RunSummary;
use App\Infrastructure\Reliability\Models\AlertRule;
use App\Support\Logging\SecretRedactor;
use App\Support\Organizations\OrganizationContext;
use Throwable;

/**
 * Asks every enabled rule whether it is true (§15).
 *
 * A sweep rather than a set of listeners, for the reason every other run in
 * this product is one: it asks a question about **rows** — which rules exist,
 * what is currently true — so a scheduler that was down for an hour catches
 * up on the next pass rather than missing an hour of alerts permanently (ADR
 * 0031). An event-driven alerter misses exactly the events that happen while
 * it is broken, which is when they matter most.
 *
 * **One rule failing never stops the rest.** A rule whose evaluation throws
 * is a rule with a bad target or a subject whose source is down; the other
 * nineteen still run, and the failure is recorded against that rule through
 * the redactor.
 *
 * **An installation with no rules is a completed run that changed nothing**,
 * not an error and not silence: core ships no rules at all, deliberately, and
 * the run record is where "why have I had no alerts" gets its answer.
 */
final readonly class EvaluateAlerts implements AutomationRun
{
    public function __construct(
        private OrganizationContext $organizations,
        private EvaluateAlertRule $evaluator,
        private SecretRedactor $redactor,
    ) {}

    public function handle(): RunSummary
    {
        return $this->organizations->withoutBoundary(function (): RunSummary {
            $summary = new RunSummary;

            // Consumed inside the callback: a builder handed back out of it
            // would be scoped again by the time anything ran it, and the
            // symptom is an empty result with no error.
            foreach (AlertRule::query()->where('enabled', true)->cursor() as $rule) {
                $summary = $summary->examining();

                try {
                    $outcome = $this->evaluator->handle($rule);
                } catch (Throwable $exception) {
                    $summary = $summary->failing(new RunItem(
                        ItemOutcome::Failed,
                        AlertRule::class,
                        $rule->id,
                        $rule->name,
                        $this->redactor->redactString($exception->getMessage()),
                    ));

                    continue;
                }

                if ($outcome['raised'] === 0 && $outcome['cleared'] === 0) {
                    // Still true, or still fine. Either way nothing changed,
                    // and a run that reported twenty changes every five
                    // minutes would make the one rule that actually fired
                    // impossible to see.
                    $summary = $summary->skipping();

                    continue;
                }

                $summary = $summary->changing(new RunItem(
                    ItemOutcome::Changed,
                    AlertRule::class,
                    $rule->id,
                    $rule->name,
                    sprintf('%d raised, %d cleared', $outcome['raised'], $outcome['cleared']),
                ));
            }

            return $summary;
        });
    }
}
