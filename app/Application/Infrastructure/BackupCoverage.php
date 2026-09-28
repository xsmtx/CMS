<?php

declare(strict_types=1);

namespace App\Application\Infrastructure;

use App\Domain\Provisioning\ServiceStatus;
use App\Infrastructure\Backup\Models\BackupProtection;
use App\Infrastructure\Crm\Models\Customer;
use App\Infrastructure\Provisioning\Models\Service;
use Carbon\CarbonImmutable;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * Which of the things this installation sells nothing is backing up (§12).
 *
 * **This is the query the whole family exists for.** Veeam knows what it
 * backs up and so does JetBackup; only this installation knows what it sold.
 * The difference is the answer nobody else can give, and it is the one an
 * operator finds out the hard way otherwise.
 *
 * **Unprotected and stale are different, and the distinction is the safety
 * rail.** A source that is down reports nothing, and "nothing" read naively
 * means every customer has lost their backups — a screen that said so at
 * three in the morning would be believed once and ignored for ever. So:
 *
 * - **stale** is a protection that exists and whose last good copy is older
 *   than the operator's threshold. Something is trying and not succeeding.
 * - **unprotected** is a service no live protection names at all. Nothing is
 *   trying.
 *
 * A service that was protected yesterday and is missing from today's answer
 * is the first, not the second, because the row is retired rather than
 * deleted and a retired row still says who used to protect it.
 *
 * **Only a service that is actually running counts as unprotected.** A
 * terminated or pending service has nothing to back up, and listing it would
 * bury the four that matter under four hundred that do not.
 */
final readonly class BackupCoverage
{
    /**
     * The service states a backup is expected for.
     *
     * A suspended service still holds the customer's data and is the one
     * most likely to be deleted next; one in grace or waiting out a
     * cancellation is still running and still theirs. Expecting a backup
     * only of `active` would leave exactly the services somebody is about to
     * lose out of the list.
     *
     * `provisioning` and `failed` are left out because there may be nothing
     * there yet, and `pending` and `terminated` because there is nothing
     * there at all.
     */
    private const array Expected = [
        ServiceStatus::Active,
        ServiceStatus::Suspended,
        ServiceStatus::GracePeriod,
        ServiceStatus::CancelPending,
    ];

    /**
     * Services nothing is protecting.
     *
     * @return LengthAwarePaginator<int, Service>
     */
    public function unprotected(int $perPage = 50): LengthAwarePaginator
    {
        return Service::query()
            ->with(Customer::displayNameWith('customer'))
            ->whereIn('status', array_map(
                static fn (ServiceStatus $status): string => $status->value,
                self::Expected,
            ))
            ->whereNotExists(static fn ($query) => $query
                ->selectRaw('1')
                ->from('backup_protections')
                ->whereColumn('backup_protections.service_id', 'services.id')
                ->whereNull('backup_protections.retired_at'))->oldest()
            ->paginate($perPage)
            ->withQueryString();
    }

    /**
     * The three figures on the screen.
     *
     * **Each one counts exactly what its list shows**, which is not the same
     * unit for all three and has to be that way: pressing a figure filters to
     * a list, so a count that did not match the rows under it would read as a
     * bug. `unprotected` is a count of **services**, because a service with
     * nobody looking after it is the thing to act on; `stale` and `protected`
     * are counts of **protections**, because a broken job is the thing to
     * fix, and a service protected twice has two jobs to think about.
     *
     * They therefore do not sum to anything, and were never meant to.
     *
     * Counted rather than derived from a page, because a screen showing fifty
     * rows of four hundred must still be able to say four hundred.
     *
     * @return array{protected: int, stale: int, unprotected: int}
     */
    public function summary(int $staleAfterDays, ?CarbonImmutable $now = null): array
    {
        $cutoff = ($now ?? CarbonImmutable::now())->subDays($staleAfterDays);

        $expected = array_map(
            static fn (ServiceStatus $status): string => $status->value,
            self::Expected,
        );

        $unprotected = Service::query()
            ->whereIn('status', $expected)
            ->whereNotExists(static fn ($query) => $query
                ->selectRaw('1')
                ->from('backup_protections')
                ->whereColumn('backup_protections.service_id', 'services.id')
                ->whereNull('backup_protections.retired_at'))
            ->count();

        $live = BackupProtection::query()->live();

        $fresh = (clone $live)
            ->whereNotNull('last_good_at')
            ->where('last_good_at', '>=', $cutoff)
            ->count();

        return [
            'protected' => $fresh,
            'stale' => $live->count() - $fresh,
            'unprotected' => $unprotected,
        ];
    }

    /**
     * The inventory: live protections with a good copy inside the window.
     *
     * §12 asks for a unified protected-resource view, and this is it — one
     * list across every source, which is the thing an operator cannot get
     * from four vendors' consoles. It is also what makes the third figure on
     * the screen pressable: a count that filters nothing would be a control
     * that lies.
     *
     * @return LengthAwarePaginator<int, BackupProtection>
     */
    public function healthy(int $staleAfterDays, int $perPage = 50, ?CarbonImmutable $now = null): LengthAwarePaginator
    {
        $cutoff = ($now ?? CarbonImmutable::now())->subDays($staleAfterDays);

        return BackupProtection::query()
            ->with(['service', ...Customer::displayNameWith('customer')])
            ->live()
            ->whereNotNull('last_good_at')
            ->where('last_good_at', '>=', $cutoff)
            ->orderByDesc('last_good_at')
            ->paginate($perPage)
            ->withQueryString();
    }

    /**
     * Protections whose last good copy is older than the threshold.
     *
     * A protection with no good copy at all is **not** here: a resource added
     * to a job this afternoon has never had one and is not a failure. It is
     * counted in `stale` by the summary, because "nothing has succeeded yet"
     * is exactly what an operator wants to see, and it is listed with a word
     * rather than an invented age.
     *
     * @return LengthAwarePaginator<int, BackupProtection>
     */
    public function stale(int $staleAfterDays, int $perPage = 50, ?CarbonImmutable $now = null): LengthAwarePaginator
    {
        $cutoff = ($now ?? CarbonImmutable::now())->subDays($staleAfterDays);

        return BackupProtection::query()
            ->with(['service', ...Customer::displayNameWith('customer')])
            ->live()
            ->where(static fn ($query) => $query
                ->whereNull('last_good_at')
                ->orWhere('last_good_at', '<', $cutoff))
            // Nothing has ever succeeded first, then oldest copy first. A
            // list sorted the other way would put the most broken thing on
            // the estate at the bottom.
            ->orderByRaw('last_good_at IS NOT NULL')
            ->orderBy('last_good_at')
            ->paginate($perPage)
            ->withQueryString();
    }
}
