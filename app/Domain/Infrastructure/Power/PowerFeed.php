<?php

declare(strict_types=1);

namespace App\Domain\Infrastructure\Power;

/**
 * Which of a rack's two feeds an outlet is on (§11).
 *
 * **A and B, and the whole point of the family.** A device fed from one feed
 * goes down when that feed does; a device fed from both does not. Every
 * question anybody asks of a power diagram is a version of "is this one
 * redundant", and a platform that could not say which side an outlet is on
 * could not answer any of them.
 *
 * `Unknown` is what a PDU that does not say gets. It is not `A`: guessing
 * would make a single-fed device look redundant, which is the one wrong
 * answer that costs an outage.
 */
enum PowerFeed: string
{
    case A = 'a';
    case B = 'b';
    case Unknown = 'unknown';

    public function labelKey(): string
    {
        return 'dcim.power.feeds.'.$this->value;
    }
}
