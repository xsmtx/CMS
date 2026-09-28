<?php

declare(strict_types=1);

namespace App\Domain\Infrastructure\Virtualisation;

/**
 * What a hypervisor says one machine is doing (§10).
 *
 * **`Paused` and `Stopped` are kept apart** because they are undone
 * differently and because one of them is usually a mistake: a machine
 * somebody paused for a snapshot and forgot is serving nothing and holding
 * all its memory, which is the single most common way a cluster runs out of
 * RAM with half its machines idle.
 *
 * `Unknown` is what a hypervisor that does not say gets. Deliberately not
 * `Stopped`: a machine whose state nobody reported has not been shut down,
 * and a screen that said it had would send somebody to start a machine that
 * is already running.
 */
enum MachineState: string
{
    case Running = 'running';
    case Paused = 'paused';
    case Stopped = 'stopped';
    case Unknown = 'unknown';

    public function labelKey(): string
    {
        return 'infrastructure.virtualisation.states.'.$this->value;
    }

    /**
     * A word `status.ts` knows, pinned for every enum by `VocabularyTest`.
     *
     * Stopped is `neutral` rather than `critical`: a machine that is off is
     * very often off on purpose, and this platform does not know which. The
     * alert rule an operator writes is where "this one should be running"
     * belongs.
     */
    public function tone(): string
    {
        return match ($this) {
            self::Running => 'healthy',
            self::Paused => 'warning',
            self::Stopped => 'neutral',
            self::Unknown => 'unknown',
        };
    }
}
