<?php

declare(strict_types=1);

namespace App\Domain\Infrastructure\Contracts;

use App\Domain\Infrastructure\Power\EnvironmentReading;

/**
 * Something that knows how warm, how wet and how shut the room is (§11).
 *
 * Often the same box as the PDU — a rack sensor hangs off an NMC — which is
 * why this is its own contract rather than a method on `PowerProvider`: one
 * adapter implements both, and an installation whose PDUs have no sensors
 * implements only the first. The case `AdapterArea` was split for.
 */
interface EnvironmentProvider extends InfrastructureAdapter
{
    /**
     * Every sensor, with its current reading or state.
     *
     * @return list<EnvironmentReading>
     */
    public function sensors(): array;
}
