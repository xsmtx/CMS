<?php

declare(strict_types=1);

namespace App\Domain\Health;

/**
 * Three states, because two are not enough.
 *
 * `Degraded` is what a queue with a thousand waiting jobs is: nothing is
 * broken, and somebody should look before it becomes broken. Collapsing it
 * into "failing" trains an operator to ignore the screen, and collapsing it
 * into "ok" is why nobody noticed.
 */
enum HealthState: string
{
    case Ok = 'ok';
    case Degraded = 'degraded';
    case Failing = 'failing';

    public function labelKey(): string
    {
        return 'health.states.'.$this->value;
    }

    /**
     * The worst of two, which is how an installation's overall state is
     * decided: one failing check makes the installation failing.
     */
    public function worseOf(self $other): self
    {
        $rank = [self::Ok->value => 0, self::Degraded->value => 1, self::Failing->value => 2];

        return $rank[$this->value] >= $rank[$other->value] ? $this : $other;
    }
}
