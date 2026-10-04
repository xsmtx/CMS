<?php

declare(strict_types=1);

namespace App\Application\Vendors;

use App\Domain\Provisioning\ServerStatus;
use App\Infrastructure\Provisioning\Models\Server;
use App\Infrastructure\Vendors\Models\LicenceAllocation;
use App\Infrastructure\Vendors\Models\LicencePool;
use Illuminate\Support\Collection;

/**
 * The difference between what was bought and what is running (§24).
 *
 * **This is the query the whole family exists for.** Every vendor portal can
 * say how many seats were bought; only this installation holds the server
 * list, so only it can say which of them are doing anything. Three answers,
 * and they are three different kinds of bad:
 *
 * - **spare** — seats paid for and attached to nothing. Money for nothing.
 * - **orphaned** — a seat attached to a machine that has left the fleet or
 *   been taken offline. The same money with a worse story, because somebody
 *   believed it was in use.
 * - **missing** — a machine that looks like it needs a seat and holds none.
 *   The one that costs an outage rather than money.
 *
 * **Absence of a claim is not a gap.** A pool with no `for_module` produces
 * no missing list at all, because core has no way to know which machines a
 * licence belongs on and a guess here would be a list of four hundred
 * servers that each need nothing. The same rule the orphan sweep follows
 * about an adapter that was never configured.
 */
final readonly class LicenceCoverage
{
    /**
     * Server states that mean a seat is being wasted.
     *
     * `maintenance` is deliberately not here: a machine somebody took out of
     * rotation this morning is going back in this afternoon, and reporting
     * its licence as orphaned would make the list say something different
     * every hour. `full` is a machine working hard, which is the opposite.
     */
    private const array Wasted = [ServerStatus::Offline];

    /**
     * Pools with their allocation counts, soonest problem first.
     *
     * @return Collection<int, LicencePool>
     */
    public function pools(): Collection
    {
        return LicencePool::query()
            // The allocations themselves as well as the count: the screen
            // needs to know *which* machines already hold a seat, so its
            // allocation select can leave them out.
            ->with(['vendor', 'contract', 'allocations'])
            ->withCount('allocations')
            ->orderBy('name')
            ->get();
    }

    /**
     * Seats attached to a machine that is gone or out of service.
     *
     * @return Collection<int, LicenceAllocation>
     */
    public function orphaned(): Collection
    {
        return LicenceAllocation::query()
            ->with(['pool.vendor', 'server'])
            ->where(static fn ($query) => $query
                ->whereNull('server_id')
                ->orWhereHas('server', static fn ($server) => $server->whereIn('status', array_map(
                    static fn (ServerStatus $status): string => $status->value,
                    self::Wasted,
                ))))
            ->oldest()
            ->get();
    }

    /**
     * Machines that look like they need a seat and hold none.
     *
     * Asked **per pool**, which is what makes it expressive: a cPanel licence
     * and an Imunify licence are both about cPanel machines, and each has its
     * own gap. Asking it once across every pool would answer that a machine
     * holding a cPanel seat needs nothing, which is exactly the Imunify
     * renewal nobody noticed had lapsed.
     *
     * @return list<array{pool: LicencePool, servers: Collection<int, Server>}>
     */
    public function missing(): array
    {
        $gaps = [];

        foreach ($this->pools() as $pool) {
            if ($pool->for_module === null) {
                continue;
            }

            $servers = Server::query()
                ->where('module', $pool->for_module)
                // An offline machine needs nothing. Listing it would put the
                // decommissioned box beside the live one that really is
                // running unlicensed.
                ->whereNot('status', ServerStatus::Offline->value)
                ->whereNotExists(static fn ($query) => $query
                    ->selectRaw('1')
                    ->from('licence_allocations')
                    ->whereColumn('licence_allocations.server_id', 'servers.id')
                    ->where('licence_allocations.licence_pool_id', $pool->id))
                ->orderBy('name')
                ->get();

            if ($servers->isNotEmpty()) {
                $gaps[] = ['pool' => $pool, 'servers' => $servers];
            }
        }

        return $gaps;
    }

    /**
     * The three figures on the screen.
     *
     * **Each counts exactly what its list shows**, and they are not the same
     * unit — spare counts *seats*, orphaned counts *allocations*, missing
     * counts *servers*. Pressing a figure shows the rows behind it, so a
     * count that did not match them would read as a bug. They do not sum to
     * anything and were never meant to.
     *
     * Spare is clamped at zero here and **only here**: a pool over its seat
     * count contributes nothing spare rather than a negative that would
     * silently cancel out another pool's genuinely idle seats. The overage
     * itself is on the pool's own row, where it names the pool it is about.
     *
     * @return array{spare: int, overage: int, orphaned: int, missing: int}
     */
    public function summary(): array
    {
        $spare = 0;
        $overage = 0;
        $missing = 0;

        foreach ($this->missing() as $gap) {
            $missing += $gap['servers']->count();
        }

        foreach ($this->pools() as $pool) {
            $spare += max(0, $pool->spare());
            $overage += max(0, -$pool->spare());
        }

        return [
            'spare' => $spare,
            'overage' => $overage,
            'orphaned' => $this->orphaned()->count(),
            'missing' => $missing,
        ];
    }
}
