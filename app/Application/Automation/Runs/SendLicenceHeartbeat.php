<?php

declare(strict_types=1);

namespace App\Application\Automation\Runs;

use App\Application\Licensing\LicenceState;
use App\Application\Licensing\Licensing;
use App\Domain\Automation\Contracts\AutomationRun;
use App\Domain\Automation\ItemOutcome;
use App\Domain\Automation\RunItem;
use App\Domain\Automation\RunSummary;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * Tells the vendor this installation is still here.
 *
 * **Asks a question about state, never about the clock** (ADR 0031): "has the
 * heartbeat deadline passed, or is the last contact older than half the
 * interval to it". A scheduler that was down for three days therefore catches
 * up on the next run instead of having skipped three days permanently — and a
 * licence that was inside grace comes back the moment the scheduler does.
 *
 * **Running it twice changes nothing the second time.** The first run brings a
 * fresh token whose heartbeat deadline is in the future, so the second finds
 * nothing due. That is the property every task here has to have, and it comes
 * free from the state being the guard.
 *
 * **A failure is a completed run.** The vendor being unreachable is not this
 * installation's fault and it is not an error an operator can act on beyond
 * reading it; `Licensing::heartbeat()` records the reason against the state and
 * leaves the entitlements where they were. A run that failed here would put a
 * red row on the automation screen every five minutes during a vendor outage,
 * and an operator who has seen three of those stops reading the fourth.
 *
 * The one thing that *is* a failure: a token the installation refuses. That is
 * a security event, and it is already audited by name.
 */
final readonly class SendLicenceHeartbeat implements AutomationRun
{
    public function __construct(private Licensing $licensing) {}

    public function handle(): RunSummary
    {
        $summary = new RunSummary;

        $state = LicenceState::load();

        // Most installations live here: nothing activated, nothing to say.
        if (! $state->configured) {
            return $summary;
        }

        if (! $this->isDue($state)) {
            return $summary;
        }

        $summary = $summary->examining();

        try {
            $fresh = $this->licensing->heartbeat();

            if ($fresh->lastFailure !== null) {
                /*
                 * Unreachable, and inside grace. Recorded as examined rather
                 * than failed: the entitlements did not move, so nothing
                 * changed, and a red row every five minutes during a vendor
                 * outage is a red row nobody reads.
                 */
                return $summary;
            }

            return $summary->changing(new RunItem(
                ItemOutcome::Changed,
                LicenceState::class,
                $fresh->licenceId ?? 'licence',
                $fresh->edition ?? 'licence',
                'heartbeat accepted',
            ));
        } catch (Throwable $exception) {
            // A refused token. Already audited by name; here it is the one
            // licensing outcome that belongs on the automation screen.
            return $summary->failing(new RunItem(
                ItemOutcome::Failed,
                LicenceState::class,
                $state->licenceId ?? 'licence',
                $state->edition ?? 'licence',
                $exception->getMessage(),
            ));
        }
    }

    /**
     * Whether it is time to speak to the vendor.
     *
     * Half way to the deadline rather than at it, so a five-minute scheduler
     * has many chances before the grace period starts counting — and so a
     * single failed attempt is never the one that matters.
     */
    private function isDue(LicenceState $state): bool
    {
        $now = CarbonImmutable::now();

        if ($state->heartbeatBy === null || $state->lastContactAt === null) {
            return true;
        }

        if ($state->heartbeatBy->isBefore($now)) {
            return true;
        }

        // `diffInSeconds` is a float in Carbon 3, and half a window is a whole
        // number of seconds or it is nothing.
        $window = (int) $state->lastContactAt->diffInSeconds($state->heartbeatBy, absolute: true);

        return $state->lastContactAt->addSeconds(intdiv($window, 2))->isBefore($now);
    }
}
