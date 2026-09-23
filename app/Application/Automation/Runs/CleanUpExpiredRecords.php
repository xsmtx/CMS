<?php

declare(strict_types=1);

namespace App\Application\Automation\Runs;

use App\Domain\Automation\Contracts\AutomationRun;
use App\Domain\Automation\ItemOutcome;
use App\Domain\Automation\RunItem;
use App\Domain\Automation\RunSummary;
use App\Infrastructure\Automation\Models\AutomationRunRecord;
use App\Infrastructure\Notifications\Models\InAppNotification;
use App\Infrastructure\Ordering\Models\Cart;
use App\Support\Organizations\OrganizationContext;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Deletes what is genuinely finished with.
 *
 * Four things, and the list is short on purpose. Everything financial,
 * every audit record and every delivery log stays: those are the rows
 * somebody asks about a year later, and a retention policy that quietly
 * deletes them turns "did we tell the customer" into a shrug.
 *
 * What goes:
 *
 * - **Expired carts.** A cart is a working document that references the
 *   catalog and is repriced on every read; an abandoned one holds no
 *   history at all.
 * - **Read in-app notifications past their retention.** The delivery log
 *   keeps the record that the message was sent; this is only the copy on
 *   the customer's bell.
 * - **Personal access tokens that have expired.** A token past its date is
 *   already refused; keeping the row invites somebody to extend it.
 * - **Automation run details older than the retention window** — the items,
 *   not the runs. The counts on a run are what an operator reads a year
 *   later; the per-row detail is what they read a week later.
 *
 * Every retention is configured, and every default is generous. A cleanup
 * that deletes something somebody wanted is not undone by a shorter run
 * next time.
 */
final readonly class CleanUpExpiredRecords implements AutomationRun
{
    public function __construct(private OrganizationContext $organizations) {}

    public function handle(): RunSummary
    {
        $summary = new RunSummary;

        foreach ($this->jobs() as $label => $job) {
            $summary = $summary->examining();

            try {
                $deleted = (int) $this->organizations->withoutBoundary($job);

                $summary = $deleted === 0
                    ? $summary->skipping()
                    : $summary->changing(new RunItem(
                        ItemOutcome::Changed,
                        null,
                        null,
                        $label,
                        (string) $deleted,
                    ));
            } catch (Throwable $exception) {
                $summary = $summary->failing(new RunItem(
                    ItemOutcome::Failed,
                    null,
                    null,
                    $label,
                    $exception->getMessage(),
                ));
            }
        }

        return $summary;
    }

    /**
     * @return array<string, Closure(): int>
     */
    private function jobs(): array
    {
        $now = CarbonImmutable::now();

        return [
            'carts' => static fn (): int => Cart::query()
                ->whereNotNull('expires_at')
                ->where('expires_at', '<', $now)
                ->delete(),

            'notifications' => fn (): int => InAppNotification::query()
                ->whereNotNull('read_at')
                ->where('read_at', '<', $now->subDays($this->days('notifications', 90)))
                ->delete(),

            'tokens' => fn (): int => (int) DB::table('personal_access_tokens')
                ->whereNotNull('expires_at')
                ->where('expires_at', '<', $now->subDays($this->days('tokens', 30)))
                ->delete(),

            'run_details' => fn (): int => DB::table('automation_run_items')
                ->whereIn(
                    'run_id',
                    AutomationRunRecord::query()
                        ->where('started_at', '<', $now->subDays($this->days('run_items', 90)))
                        ->select('id'),
                )
                ->delete(),
        ];
    }

    private function days(string $key, int $default): int
    {
        return (int) config('platform.automation.retention.'.$key, $default);
    }
}
