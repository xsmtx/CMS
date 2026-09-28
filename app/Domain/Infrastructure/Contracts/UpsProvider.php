<?php

declare(strict_types=1);

namespace App\Domain\Infrastructure\Contracts;

use App\Domain\Infrastructure\Power\UpsStatus;

/**
 * Something that knows whether the mains is still there (§11).
 *
 * **Read only, and there is nothing sensible to write.** A UPS takes a
 * self-test and a shutdown command over most of these APIs; neither belongs
 * in a platform whose operator is not standing next to it.
 */
interface UpsProvider extends InfrastructureAdapter
{
    /**
     * Every unit this source knows about.
     *
     * @return list<UpsStatus>
     */
    public function units(): array;
}
