<?php

declare(strict_types=1);

namespace App\Domain\Intelligence;

/**
 * What a comparison between this platform and a provider concluded (§22).
 *
 * §22 names five and four of them are conclusions. **`Unknown` is the absence
 * of one**, and keeping it apart from `Drift` is the most important thing in
 * this enum: a machine that might have been resized and a machine nobody
 * could ask are different things to act on, and the second is a monitoring
 * problem rather than a customer's. Folding them together would put a
 * provider's outage in front of an operator as four hundred customers whose
 * accounts had apparently changed.
 *
 * It is the same three-valued instinct as an availability check that did not
 * answer (ADR 0028), a component whose advisories nobody read, and a metric
 * that has gone stale. This is the phase where getting it wrong would suspend
 * somebody.
 *
 * **`Healthy` is recorded and never raised.** A finding is only written when
 * there is something to say; agreement is the absence of a row, which is what
 * makes the queue a queue. The member exists because the comparison has to be
 * able to *return* it.
 */
enum ReconciliationClass: string
{
    /** The provider agrees. No finding is written. */
    case Healthy = 'healthy';

    /** Both sides know about it and disagree about what it is. */
    case Drift = 'drift';

    /** The provider has it and this platform does not. */
    case Orphan = 'orphan';

    /** This platform has it and the provider does not. */
    case Missing = 'missing';

    /** Nobody could ask. Never a conclusion about the resource itself. */
    case Unknown = 'unknown';

    public function labelKey(): string
    {
        return 'intelligence.classes.'.$this->value;
    }

    /**
     * A word `status.ts` knows, pinned for every enum by `VocabularyTest`.
     *
     * `Missing` is the critical one: the customer is paying for something
     * that is not there. `Orphan` is a warning because it usually costs
     * money rather than breaking anything, and `Unknown` is deliberately
     * `unknown` rather than a fault — a provider that did not answer has not
     * told us anything is wrong.
     */
    public function tone(): string
    {
        return match ($this) {
            self::Healthy => 'healthy',
            self::Drift => 'warning',
            self::Orphan => 'warning',
            self::Missing => 'critical',
            self::Unknown => 'unknown',
        };
    }

    /**
     * Whether this is worth a row.
     *
     * Agreement is the absence of a finding, which is what makes the queue
     * something an operator can finish reading.
     */
    public function isFinding(): bool
    {
        return $this !== self::Healthy;
    }
}
