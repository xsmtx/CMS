<?php

declare(strict_types=1);

namespace App\Domain\Health\Contracts;

use App\Domain\Health\HealthReport;

/**
 * One thing that can be wrong with an installation.
 *
 * Two rules, both of which exist because a health page is opened when
 * something is already broken:
 *
 * - **A check never throws.** A check that dies because Redis is down has
 *   taken the page down with it, which is the one moment it was needed.
 *   `HealthChecks` catches anyway; the check catching first is what lets it
 *   say something useful about the failure.
 * - **A check never returns configuration.** Not a DSN, not a host, not a
 *   key prefix. "Reachable in 3 ms" proves the database is configured
 *   without publishing how.
 */
interface HealthCheck
{
    public function key(): string;

    public function run(): HealthReport;
}
