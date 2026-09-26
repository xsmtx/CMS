<?php

declare(strict_types=1);

namespace App\Application\Automation\Runs;

use App\Application\Network\AccessGrants;
use App\Domain\Automation\Contracts\AutomationRun;
use App\Domain\Automation\ItemOutcome;
use App\Domain\Automation\RunItem;
use App\Domain\Automation\RunSummary;
use App\Infrastructure\Network\Models\AccessGrant;
use App\Support\Organizations\OrganizationContext;
use Throwable;

/**
 * Writes down that a grant has run out (§17).
 *
 * **Nothing depends on this running on time**, and that is the point. Whether
 * a grant is live is a question about its own two timestamps, asked by
 * `AccessGrants::allows()` at the moment somebody uses it — so a scheduler
 * that was down for three hours leaves nobody holding access they should not
 * have. ADR 0031's rule, applied to a permission rather than to an invoice:
 * a run asks a question about rows, never about the clock.
 *
 * What the sweep is *for* is the record. An operator reading the list wants
 * to see that a grant ended, with a reason and a time, rather than working it
 * out from a date in the past — and an incident review asking "who could get
 * into that box on the eleventh" wants a row that says so.
 *
 * One grant failing never stops the rest, which is the sweep rule every task
 * here follows.
 */
final readonly class ExpireAccessGrants implements AutomationRun
{
    public function __construct(
        private OrganizationContext $organizations,
        private AccessGrants $grants,
    ) {}

    public function handle(): RunSummary
    {
        return $this->organizations->withoutBoundary(function (): RunSummary {
            $summary = new RunSummary;

            // Consumed inside the callback: a builder handed back out of it is
            // scoped again by the time anything runs it, and the symptom is an
            // empty result with no error.
            foreach (AccessGrant::query()->lapsed()->with('holder')->cursor() as $grant) {
                $summary = $summary->examining();

                try {
                    $this->grants->revoke($grant, null, 'expired');

                    $summary = $summary->changing(new RunItem(
                        ItemOutcome::Changed,
                        AccessGrant::class,
                        $grant->id,
                        $grant->capability->value,
                        $grant->holder?->name,
                    ));
                } catch (Throwable $exception) {
                    $summary = $summary->failing(new RunItem(
                        ItemOutcome::Failed,
                        AccessGrant::class,
                        $grant->id,
                        $grant->capability->value,
                        $exception->getMessage(),
                    ));
                }
            }

            return $summary;
        });
    }
}
