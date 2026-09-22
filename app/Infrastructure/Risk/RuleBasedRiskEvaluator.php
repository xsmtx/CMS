<?php

declare(strict_types=1);

namespace App\Infrastructure\Risk;

use App\Domain\Risk\Contracts\RiskEvaluator;
use App\Domain\Risk\RiskAssessment;
use App\Domain\Risk\RiskDecision;
use App\Domain\Risk\RiskReason;
use App\Domain\Risk\RiskSubject;
use App\Domain\Shared\Money;

/**
 * The risk layer core ships.
 *
 * Reads only signals the platform already has, names no vendor, and holds
 * rather than refuses: the useful answer to most suspicious orders is that
 * a human should look, and an automatic refusal turns a cautious rule into
 * lost business.
 *
 * Nothing here denies outright by default. Denial is available and
 * configurable, but an installation that has not tuned its thresholds
 * should not be silently turning customers away.
 */
final readonly class RuleBasedRiskEvaluator implements RiskEvaluator
{
    /**
     * @param  array<string, mixed>  $rules
     */
    public function __construct(private array $rules = []) {}

    public function evaluate(RiskSubject $subject): RiskAssessment
    {
        $reasons = [];
        $score = 0;

        foreach ($this->checks($subject) as [$triggered, $reason, $weight]) {
            if (! $triggered) {
                continue;
            }

            $reasons[] = $reason;
            $score += $weight;
        }

        if ($reasons === []) {
            return RiskAssessment::allow();
        }

        $denyAt = $this->intRule('deny_score', 0);
        $reviewAt = $this->intRule('review_score', 1);

        $decision = match (true) {
            $denyAt > 0 && $score >= $denyAt => RiskDecision::Deny,
            $score >= $reviewAt => RiskDecision::Review,
            default => RiskDecision::Allow,
        };

        return new RiskAssessment($decision, $reasons, $score);
    }

    /**
     * Every rule, as a triple of (did it fire, why, how much it weighs).
     *
     * A list rather than a chain of ifs so the weights sit next to the
     * conditions and an operator reading the config can see what each one
     * costs.
     *
     * @return list<array{0: bool, 1: RiskReason, 2: int}>
     */
    private function checks(RiskSubject $subject): array
    {
        $checks = [];

        $threshold = $this->intRule('high_value_minor', 0);

        if ($threshold > 0) {
            $limit = Money::ofMinor($threshold, $subject->total->currency);

            $checks[] = [
                $subject->total->isGreaterThan($limit),
                new RiskReason('high_order_value', ['threshold' => $limit->toDecimalString()]),
                $this->intRule('high_value_weight', 2),
            ];
        }

        $newAccountDays = $this->intRule('new_account_days', 0);
        $age = $subject->accountAgeInDays();

        if ($newAccountDays > 0 && $age !== null) {
            $checks[] = [
                $age < $newAccountDays,
                new RiskReason('new_account', ['days' => $age]),
                $this->intRule('new_account_weight', 1),
            ];
        }

        if ($this->boolRule('flag_first_order', false)) {
            $checks[] = [
                $subject->isFirstOrder(),
                new RiskReason('no_account_history'),
                $this->intRule('first_order_weight', 1),
            ];
        }

        $velocity = $this->intRule('velocity_orders', 0);

        if ($velocity > 0) {
            $checks[] = [
                $subject->recentOrders >= $velocity,
                new RiskReason('order_velocity', [
                    'count' => $subject->recentOrders,
                    'hours' => $this->intRule('velocity_hours', 24),
                ]),
                $this->intRule('velocity_weight', 2),
            ];
        }

        $failed = $this->intRule('failed_payments', 0);

        if ($failed > 0) {
            $checks[] = [
                $subject->failedPayments >= $failed,
                new RiskReason('failed_payments', ['count' => $subject->failedPayments]),
                $this->intRule('failed_payments_weight', 2),
            ];
        }

        if ($this->boolRule('flag_country_mismatch', true)) {
            // Null means we could not tell, which is not the same as a
            // match and must not silently pass.
            $checks[] = [
                $subject->countryMismatch() === true,
                new RiskReason('country_mismatch', [
                    'billing' => $subject->billingCountry,
                    'origin' => $subject->ipCountry,
                ]),
                $this->intRule('country_mismatch_weight', 1),
            ];
        }

        if ($this->boolRule('flag_unverified_email', false)) {
            $checks[] = [
                ! $subject->emailVerified,
                new RiskReason('unverified_email'),
                $this->intRule('unverified_email_weight', 1),
            ];
        }

        return $checks;
    }

    private function intRule(string $key, int $default): int
    {
        $value = $this->rules[$key] ?? $default;

        return is_numeric($value) ? (int) $value : $default;
    }

    private function boolRule(string $key, bool $default): bool
    {
        $value = $this->rules[$key] ?? $default;

        return is_bool($value) ? $value : $default;
    }
}
