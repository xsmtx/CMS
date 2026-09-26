<?php

declare(strict_types=1);

namespace App\Application\Network;

use App\Application\Network\Exceptions\AddressingFailed;
use App\Domain\Network\AddressState;
use App\Domain\Network\IpAddress;
use App\Infrastructure\Network\Models\IpAddressRecord;
use App\Infrastructure\Network\Models\IpPrefixRecord;
use Illuminate\Support\Facades\DB;

/**
 * The next address somebody can have out of a prefix.
 *
 * **Free is computed, not stored.** There is no row for an unused address — a
 * /64 has eighteen quintillion of them — so this walks the prefix's usable range
 * and returns the first address no row in it claims. The rows are read in one
 * ordered query on the binary column, which is why that column is binary.
 *
 * **It takes a lock on the prefix, and that is not optional.** Two provisioning
 * jobs finishing in the same second would otherwise both read the same gap and
 * both hand out 192.0.2.7 — the classic read-modify-write, the same shape as the
 * lost update the ledger takes a lock for. A row is inserted inside the lock, so
 * the second job sees it.
 *
 * **A reserved or quarantined address is skipped, never reused.** Reserved is
 * somebody's decision; quarantined is an address that has just been released and
 * should cool off — handing a spammer's old address to a new customer the next
 * morning gives them mail that silently fails for a month.
 */
final readonly class AllocateAddress
{
    /**
     * How many addresses to consider in one pass.
     *
     * A prefix whose first ten thousand addresses are all taken is a prefix an
     * operator should be splitting rather than one this should grind through,
     * and an unbounded walk over a /64 is a request that never returns.
     */
    private const int SCAN_LIMIT = 10000;

    /**
     * The first free address in the prefix, as a row in `available` state.
     *
     * The row is created here so that the caller's assignment has something to
     * point at, and so that the lock covers the reservation of the address
     * rather than only the reading of it.
     */
    public function handle(IpPrefixRecord $prefix): IpAddressRecord
    {
        return DB::transaction(function () use ($prefix): IpAddressRecord {
            // Lock the prefix row, not the address rows: the address being
            // allocated has no row yet, so there is nothing else two callers
            // could both hold.
            IpPrefixRecord::query()
                ->whereKey($prefix->id)
                ->lockForUpdate()
                ->firstOrFail();

            $record = IpAddressRecord::within(
                $prefix,
                $this->firstFree($prefix),
                AddressState::Available,
            );

            $record->save();

            return $record;
        });
    }

    /**
     * The first address in the prefix that no row claims.
     */
    private function firstFree(IpPrefixRecord $prefix): IpAddress
    {
        [$first, $last] = $prefix->prefix()->usableRange();

        /** @var list<string> $taken */
        $taken = IpAddressRecord::query()
            ->where('ip_prefix_id', $prefix->id)
            ->whereBetween('address_bytes', [$first->bytes, $last->bytes])
            ->orderBy('address_bytes')
            ->limit(self::SCAN_LIMIT)
            ->pluck('address_bytes')
            ->all();

        $claimed = array_flip($taken);
        $candidate = $first;

        for ($scanned = 0; $scanned < self::SCAN_LIMIT; $scanned++) {
            if (! isset($claimed[$candidate->bytes])) {
                return $candidate;
            }

            if ($candidate->equals($last)) {
                break;
            }

            $next = $candidate->next();

            if ($next === null) {
                break;
            }

            $candidate = $next;
        }

        throw AddressingFailed::prefixFull($prefix->cidr);
    }
}
