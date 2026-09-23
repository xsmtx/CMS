<?php

declare(strict_types=1);

namespace App\Application\Health;

use App\Domain\Health\Contracts\HealthCheck;
use App\Domain\Health\HealthReport;
use App\Domain\Health\HealthState;
use App\Support\Logging\SecretRedactor;
use Throwable;

/**
 * Runs every check and says what the installation's state is.
 *
 * **A check that throws is caught here** and reported as failing with its
 * message redacted. A health page that dies because Redis is down has taken
 * itself out at the one moment it was needed, and the operator is left
 * reading a stack trace instead of a list.
 *
 * The overall state is the worst of the parts. One failing check makes the
 * installation failing, because an operator who has to read six lines to
 * find out whether anything is wrong will stop reading them.
 */
final readonly class HealthChecks
{
    /**
     * @param  iterable<HealthCheck>  $checks
     */
    public function __construct(
        private iterable $checks,
        private SecretRedactor $redactor,
    ) {}

    /**
     * @return list<HealthReport>
     */
    public function run(): array
    {
        $reports = [];

        foreach ($this->checks as $check) {
            try {
                $reports[] = $check->run();
            } catch (Throwable $exception) {
                $reports[] = HealthReport::failing(
                    $check->key(),
                    $this->redactor->redactString($exception->getMessage()),
                );
            }
        }

        return $reports;
    }

    /**
     * @param  list<HealthReport>  $reports
     */
    public function overall(array $reports): HealthState
    {
        $state = HealthState::Ok;

        foreach ($reports as $report) {
            $state = $state->worseOf($report->state);
        }

        return $state;
    }
}
