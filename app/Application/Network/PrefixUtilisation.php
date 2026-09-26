<?php

declare(strict_types=1);

namespace App\Application\Network;

use App\Domain\Network\AddressState;
use App\Infrastructure\Network\Models\IpPrefixRecord;
use Illuminate\Support\Facades\DB;

/**
 * How full a network is.
 *
 * Counted from the rows, because there is no row for a free address: used is
 * what exists, capacity is the prefix's own usable range, and free is the
 * subtraction. One grouped query for a whole list rather than one per row.
 *
 * **A /64 has no usable percentage**, and saying so is the point. Its capacity
 * is larger than a PHP integer, and a bar reading 0.0000000001% is a bar that
 * tells an operator nothing while looking like it does — so the answer is null
 * and the screen prints the count without a proportion.
 */
final readonly class PrefixUtilisation
{
    /**
     * @param  list<string>  $prefixIds
     * @return array<string, array{used: int, reserved: int, quarantined: int}>
     */
    public function counts(array $prefixIds): array
    {
        if ($prefixIds === []) {
            return [];
        }

        $rows = DB::table('ip_addresses')
            ->select('ip_prefix_id', 'state', DB::raw('count(*) as total'))
            ->whereIn('ip_prefix_id', $prefixIds)
            ->groupBy('ip_prefix_id', 'state')
            ->get();

        $counts = [];

        foreach ($rows as $row) {
            $id = (string) $row->ip_prefix_id;

            $counts[$id] ??= ['used' => 0, 'reserved' => 0, 'quarantined' => 0];

            // Everything with a row is occupying an address as far as capacity
            // is concerned - an available row is one somebody released without
            // quarantining, and it is still not free until it is used again.
            $counts[$id]['used'] += (int) $row->total;

            if ($row->state === AddressState::Reserved->value) {
                $counts[$id]['reserved'] += (int) $row->total;
            }

            if ($row->state === AddressState::Quarantined->value) {
                $counts[$id]['quarantined'] += (int) $row->total;
            }
        }

        return $counts;
    }

    /**
     * How many addresses a prefix can hold, or null when the number is one
     * nobody should be shown.
     */
    public function capacity(IpPrefixRecord $prefix): ?int
    {
        return $prefix->prefix()->usableCount();
    }
}
