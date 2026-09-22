<?php

declare(strict_types=1);

namespace App\Infrastructure\Risk;

use App\Domain\Risk\Contracts\RiskEvaluator;
use App\Domain\Risk\RiskAssessment;
use App\Domain\Risk\RiskSubject;

/**
 * Lets everything through.
 *
 * What an installation gets when it turns risk off. It is a real
 * implementation rather than a null check in the order path, so the
 * decision is still recorded on the order: "allowed, by an evaluator that
 * allows everything" is a fact worth having when an order is looked at
 * later.
 */
final readonly class AllowAllRiskEvaluator implements RiskEvaluator
{
    public function evaluate(RiskSubject $subject): RiskAssessment
    {
        return RiskAssessment::allow();
    }
}
