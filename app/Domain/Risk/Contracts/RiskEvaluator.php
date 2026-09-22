<?php

declare(strict_types=1);

namespace App\Domain\Risk\Contracts;

use App\Domain\Risk\RiskAssessment;
use App\Domain\Risk\RiskSubject;

/**
 * Decides whether an order should be fulfilled automatically.
 *
 * Provider-neutral on purpose: core ships a rule-based evaluator over
 * signals it already holds, and a module registers a vendor's without
 * ordering code learning that vendor's name.
 */
interface RiskEvaluator
{
    public function evaluate(RiskSubject $subject): RiskAssessment;
}
