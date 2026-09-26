<?php

declare(strict_types=1);

namespace App\Application\Reliability;

use App\Domain\Reliability\AlertState;
use App\Domain\Reliability\Observation;
use App\Infrastructure\Reliability\Models\Alert;
use App\Infrastructure\Reliability\Models\AlertRule;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;

/**
 * One rule against what is currently true: raise, keep, or clear.
 *
 * **The whole of the de-duplication lives here and in one index.** An open
 * alert is found by `(rule, subject_key)` with a null `cleared_at`; a second
 * raise finds it, bumps `occurrences` and `last_seen_at`, and writes nothing
 * else. A disk that crosses 90% every minute for six hours is one alert seen
 * 360 times, which is a sentence an operator can act on — 360 rows is a list
 * they close.
 *
 * **Clearing is the other half and it is the half people forget.** A rule
 * that only ever raised would fill a screen with things that stopped being
 * true days ago, and an operator who has learned that the list is stale is an
 * operator who does not read it. So every evaluation closes the open alerts
 * whose subject is no longer bad, and the row is kept.
 *
 * **`for_minutes` is honoured without storing a history.** The alert's own
 * `first_seen_at` is the history: the row is created on the first bad
 * observation and only becomes visible — `Raised` rather than `Suppressed` —
 * once it has been bad for long enough. That is why a CPU spike lasting nine
 * seconds never reaches anybody, and why the platform needs no series to
 * decide it.
 *
 * Nothing here notifies. `NotificationEvent` and `Notifier` already exist
 * (ADR 0029), and a rule that sent its own mail would be a second place that
 * knows about opt-outs, locales and delivery records.
 */
final readonly class EvaluateAlertRule
{
    public function __construct(private GatherObservations $observations) {}

    /**
     * @return array{raised: int, kept: int, cleared: int}
     */
    public function handle(AlertRule $rule, ?CarbonImmutable $now = null): array
    {
        $at = $now ?? CarbonImmutable::now();

        $observations = $this->observations->for(
            $rule->subject,
            $rule->target,
            $rule->organization_id,
        );

        $bad = [];
        $raised = 0;
        $kept = 0;

        foreach ($observations as $observation) {
            if (! $this->matches($rule, $observation)) {
                continue;
            }

            $bad[$observation->key] = true;

            $this->record($rule, $observation, $at) ? $raised++ : $kept++;
        }

        return [
            'raised' => $raised,
            'kept' => $kept,
            'cleared' => $this->clearDeparted($rule, array_keys($bad), $at),
        ];
    }

    /**
     * Whether this observation should raise this rule.
     *
     * Two questions, and the order matters: an observation that is not bad by
     * its own account never reaches the threshold. A health check that is
     * `ok` is not compared against anything, and a metric is bad only when
     * the operator's own comparison says so.
     */
    private function matches(AlertRule $rule, Observation $observation): bool
    {
        if (! $observation->bad) {
            return false;
        }

        if (! $rule->subject->isNumeric()) {
            return true;
        }

        $threshold = $rule->threshold();

        // A numeric rule with no threshold matches nothing. Refused at the
        // form as well, and refused here too: a rule that alerted on
        // everything because somebody left a field blank is the one that
        // teaches people to ignore the list.
        if ($threshold === null || $rule->comparison === null || $observation->value === null) {
            return false;
        }

        return $rule->comparison->matches($observation->value, $threshold);
    }

    /**
     * Open it, or say it is still true.
     *
     * Returns true when this was the first time.
     */
    private function record(AlertRule $rule, Observation $observation, CarbonImmutable $at): bool
    {
        $existing = Alert::query()
            ->where('alert_rule_id', $rule->id)
            ->where('subject_key', $observation->key)
            ->whereNull('cleared_at')
            ->first();

        if ($existing instanceof Alert) {
            $existing->occurrences++;
            $existing->last_seen_at = $at;
            $existing->observed = $observation->observed;
            $existing->subject_label = $observation->label;
            $existing->severity = $rule->severity;

            // It has now been bad long enough. The row was already there; the
            // only thing that changes is whether anybody is told about it.
            if ($existing->state === AlertState::Suppressed && $this->held($rule, $existing, $at)) {
                $existing->state = AlertState::Raised;
            }

            $existing->save();

            return false;
        }

        try {
            Alert::query()->create([
                'organization_id' => $rule->organization_id,
                'alert_rule_id' => $rule->id,
                'subject_key' => $observation->key,
                'subject_label' => $observation->label,
                // `for_minutes` of zero raises immediately; anything else
                // starts held, and the next evaluation that still finds it bad
                // promotes it.
                'state' => $rule->for_minutes === 0 ? AlertState::Raised : AlertState::Suppressed,
                'severity' => $rule->severity,
                'observed' => $observation->observed,
                'occurrences' => 1,
                'first_seen_at' => $at,
                'last_seen_at' => $at,
            ]);
        } catch (QueryException) {
            // Two evaluations of one rule at once, which the unique index
            // refuses. Tested at the guard rather than by racing threads: a
            // flaky test is worse than none, because it gets retried until it
            // passes.
            return false;
        }

        return true;
    }

    /**
     * Whether an alert has been bad for as long as the rule asks.
     */
    private function held(AlertRule $rule, Alert $alert, CarbonImmutable $at): bool
    {
        return $alert->first_seen_at->addMinutes($rule->for_minutes)->lessThanOrEqualTo($at);
    }

    /**
     * Close what has stopped being true.
     *
     * @param  list<string>  $stillBad
     */
    private function clearDeparted(AlertRule $rule, array $stillBad, CarbonImmutable $at): int
    {
        $open = Alert::query()
            ->where('alert_rule_id', $rule->id)
            ->whereNull('cleared_at')
            ->whereNotIn('subject_key', $stillBad)
            ->get();

        foreach ($open as $alert) {
            $alert->state = AlertState::Cleared;
            $alert->cleared_at = $at;
            // The token that lets the same subject alert again. Empty means
            // open, and a unique index over nulls would not collide.
            $alert->dedupe_token = $alert->id;
            $alert->save();
        }

        return $open->count();
    }
}
