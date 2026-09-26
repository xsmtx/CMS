<?php

declare(strict_types=1);

namespace App\Application\Network;

use App\Application\Network\Exceptions\AddressingFailed;
use App\Domain\Network\IpAddress;
use App\Domain\Network\IpPrefix;
use App\Infrastructure\Network\Models\IpPool;
use App\Infrastructure\Network\Models\IpPrefixRecord;
use App\Support\Audit\Facades\Audit;
use App\Support\Identity\CurrentActor;
use Illuminate\Support\Facades\DB;

/**
 * Put a network into a pool, or take one out.
 *
 * Three rules, and each of them exists because the alternative is a screen that
 * looks right and answers wrong:
 *
 * **A prefix belongs to its family's pool.** A v6 network in a v4 pool would sit
 * in a list filtered by family and never appear.
 *
 * **Two prefixes may not overlap, but one may contain another.** `10.0.0.0/8`
 * and `10.1.0.0/16` are a supernet and its subnet, which is how operators
 * actually work; `10.1.0.0/16` twice is two rows each showing half the
 * addresses, neither of them wrong on its own. So the exact same network is
 * refused and a nested one is **parented** instead — the tree is worked out
 * here rather than typed in, because a parent somebody chose by hand is a parent
 * that goes stale the moment a shorter prefix is added above it.
 *
 * **A prefix with addresses in it is not deletable.** The refusal names how many
 * are in the way, the same shape as a module that is in use.
 */
final readonly class SavePrefix
{
    public function __construct(
        private CurrentActor $actor,
    ) {}

    public function create(IpPool $pool, IpPrefix $prefix, ?string $gateway = null, ?string $site = null, ?string $vlanId = null, ?string $note = null): IpPrefixRecord
    {
        if ($prefix->family !== $pool->family) {
            throw AddressingFailed::familyMismatch($prefix->text(), $pool->family->value);
        }

        if ($gateway !== null && $gateway !== '') {
            $address = IpAddress::parse($gateway);

            if (! $prefix->contains($address)) {
                throw AddressingFailed::outsidePrefix($address->text(), $prefix->text());
            }

            $gateway = $address->text();
        }

        return DB::transaction(function () use ($pool, $prefix, $gateway, $site, $vlanId, $note): IpPrefixRecord {
            $this->refuseDuplicate($pool, $prefix);

            $record = new IpPrefixRecord;

            $record->organization_id = $pool->organization_id;
            $record->ip_pool_id = $pool->id;
            $record->cidr = $prefix->text();
            $record->network_bytes = $prefix->firstAddress()->bytes;
            $record->broadcast_bytes = $prefix->lastAddress()->bytes;
            $record->prefix_length = $prefix->length;
            $record->family = $prefix->family;
            $record->gateway = $gateway === '' ? null : $gateway;
            $record->site = $site === '' ? null : $site;
            $record->vlan_id = $vlanId === '' ? null : $vlanId;
            $record->note = $note === '' ? null : $note;
            $record->parent_id = $this->parentFor($pool, $prefix)?->id;

            $record->save();

            // Anything this prefix now sits above is re-parented onto it. A /16
            // added after its own /24s would otherwise leave them hanging off
            // the /8, and the tree an operator reads would be wrong in a way
            // nothing else would ever correct.
            $this->adopt($record, $prefix);

            Audit::action('network.prefix.created')
                ->by($this->actor->model())
                ->on($record)
                ->forOrganization($record->organization_id)
                ->withMetadata(['cidr' => $record->cidr])
                ->write();

            return $record;
        });
    }

    public function delete(IpPrefixRecord $prefix): void
    {
        DB::transaction(function () use ($prefix): void {
            $addresses = $prefix->addresses()->count();

            if ($addresses > 0) {
                throw AddressingFailed::prefixInUse($prefix->cidr, $addresses);
            }

            // A subnet outlives its supernet: removing a /8 from the tree must
            // not take somebody's /24 with it.
            IpPrefixRecord::query()
                ->where('parent_id', $prefix->id)
                ->update(['parent_id' => $prefix->parent_id]);

            Audit::action('network.prefix.deleted')
                ->by($this->actor->model())
                ->on($prefix)
                ->forOrganization($prefix->organization_id)
                ->withMetadata(['cidr' => $prefix->cidr])
                ->write();

            $prefix->delete();
        });
    }

    private function refuseDuplicate(IpPool $pool, IpPrefix $prefix): void
    {
        $existing = IpPrefixRecord::query()
            ->where('organization_id', $pool->organization_id)
            ->where('network_bytes', $prefix->firstAddress()->bytes)
            ->where('prefix_length', $prefix->length)
            ->first();

        if ($existing instanceof IpPrefixRecord) {
            throw AddressingFailed::overlapping($prefix->text(), $existing->cidr);
        }
    }

    /**
     * The smallest existing prefix that contains this one.
     *
     * Smallest, so a /24 under both a /8 and a /16 hangs off the /16 — the
     * nearest true statement rather than the first one found.
     */
    private function parentFor(IpPool $pool, IpPrefix $prefix): ?IpPrefixRecord
    {
        return IpPrefixRecord::query()
            ->where('organization_id', $pool->organization_id)
            ->where('family', $prefix->family->value)
            ->where('network_bytes', '<=', $prefix->firstAddress()->bytes)
            ->where('broadcast_bytes', '>=', $prefix->lastAddress()->bytes)
            ->where('prefix_length', '<', $prefix->length)
            ->orderByDesc('prefix_length')
            ->first();
    }

    private function adopt(IpPrefixRecord $record, IpPrefix $prefix): void
    {
        IpPrefixRecord::query()
            ->where('organization_id', $record->organization_id)
            ->where('family', $prefix->family->value)
            ->whereKeyNot($record->id)
            ->where('network_bytes', '>=', $prefix->firstAddress()->bytes)
            ->where('broadcast_bytes', '<=', $prefix->lastAddress()->bytes)
            ->where('prefix_length', '>', $prefix->length)
            // Only the ones that were hanging off whatever this prefix now
            // hangs off: a /24 already parented to a /20 inside this /16 keeps
            // its nearer parent.
            ->where(fn ($query) => $query->whereNull('parent_id')->orWhere('parent_id', $record->parent_id))
            ->update(['parent_id' => $record->id]);
    }
}
