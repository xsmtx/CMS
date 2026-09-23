<?php

declare(strict_types=1);

namespace App\Domain\Automation;

/**
 * What a step in the dunning sequence does.
 *
 * Three actions, and no late fee. A late fee is money, and money on a
 * frozen document (ADR 0023) is a credit note's worth of complexity that
 * needs its own decision — whether it is a new invoice or a line on the
 * next one — rather than being invented in a phase about scheduling.
 */
enum DunningAction: string
{
    case Notify = 'notify';
    case Suspend = 'suspend';
    case Terminate = 'terminate';

    public function labelKey(): string
    {
        return 'automation.dunning.actions.'.$this->value;
    }

    public function needsEvent(): bool
    {
        return $this === self::Notify;
    }
}
