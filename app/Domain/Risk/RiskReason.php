<?php

declare(strict_types=1);

namespace App\Domain\Risk;

/**
 * One thing an evaluator noticed.
 *
 * A code rather than a sentence: the message an operator reads is a
 * translation of the code, so a reason recorded a year ago still reads in
 * the operator's language and still means what it meant.
 */
final readonly class RiskReason
{
    /**
     * @param  array<string, scalar|null>  $detail
     */
    public function __construct(
        public string $code,
        public array $detail = [],
    ) {}

    public function labelKey(): string
    {
        return 'ordering.risk_reasons.'.$this->code;
    }
}
