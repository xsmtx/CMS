<?php

declare(strict_types=1);

namespace App\Application\Network;

use App\Domain\Infrastructure\Network\DdosAttack;
use App\Domain\Infrastructure\Network\DdosVector;
use App\Domain\Network\Exceptions\InvalidAddress;
use App\Domain\Network\IpAddress;
use App\Infrastructure\Network\Models\DdosEvent;
use App\Infrastructure\Network\Models\IpAddressRecord;
use App\Infrastructure\Network\Models\IpAssignment;
use App\Infrastructure\Provisioning\Models\Service;
use Carbon\CarbonImmutable;

/**
 * Writes down an attack, and works out whose it was.
 *
 * **The attribution is the whole value of this file.** Every scrubbing
 * service on earth can tell an operator that 198.51.100.7 was hit with 40
 * Gbps of NTP reflection. None of them can say that the address belonged to a
 * customer's hosting account at the time, because none of them has the
 * billing database — and "at the time" is the part that needs `ip_assignments`
 * rather than the current holder. An attack last Tuesday on an address that
 * has since been handed to somebody else must not be attributed to its new
 * holder, which is precisely what a lookup of the *present* assignment would
 * do, and precisely why §5 made that table append-only.
 *
 * **An address nobody held is kept**, with both attributions null. An attack
 * on an address this installation does not recognise is a finding — a
 * misconfigured scrubber, a range somebody forgot to record, a neighbour's
 * address being reported to us — and throwing the row away would be throwing
 * the finding away.
 *
 * **Idempotent on the provider's own reference.** The sweep runs every few
 * minutes and an attack lasting an hour is reported again each time, with a
 * later end and usually a higher peak: the row is updated rather than
 * duplicated, which is rule 9 in one line.
 */
final readonly class RecordDdosEvent
{
    public function handle(string $organizationId, string $source, DdosAttack $attack): DdosEvent
    {
        $address = $this->addressFor($organizationId, $attack->target);
        [$customerId, $serviceId] = $this->attribute($address, $attack->startedAt);

        return DdosEvent::query()->updateOrCreate(
            ['source' => $source, 'reference' => $attack->reference],
            [
                'organization_id' => $organizationId,
                'target_address' => $this->canonical($attack->target),
                'ip_address_id' => $address?->id,
                'customer_id' => $customerId,
                'service_id' => $serviceId,
                'started_at' => $attack->startedAt,
                'ended_at' => $attack->endedAt,
                // Nullable both ways on purpose: a vendor reports one figure,
                // the other or both, and a platform that invented the missing
                // one would be a platform whose numbers could not be
                // reconciled with the transit invoice that carried them.
                'peak_gbps' => $attack->peakGbps,
                'peak_mpps' => $attack->peakMpps,
                'vectors' => array_values(array_map(
                    static fn (DdosVector $vector): string => $vector->value,
                    $attack->vectors,
                )),
                'mitigation' => $attack->mitigation,
            ],
        );
    }

    /**
     * The address row, if this installation has one.
     *
     * Matched on the **bytes**, never on the text: a vendor writing
     * `2001:db8::1` and an operator having typed `2001:0db8:0000::0001` are
     * the same address, and a text comparison would attribute neither.
     */
    private function addressFor(string $organizationId, string $target): ?IpAddressRecord
    {
        $parsed = $this->parse($target);

        if (! $parsed instanceof IpAddress) {
            return null;
        }

        return IpAddressRecord::query()
            ->where('organization_id', $organizationId)
            ->where('address_bytes', $parsed->bytes)
            ->first();
    }

    /**
     * Who held it **when the attack started**.
     *
     * The assignment that was open at that moment, which is an `assigned_at`
     * in the past and a `released_at` that is either null or later. An
     * address handed on since is attributed to whoever had it then, which is
     * the answer an abuse report or a credit request actually needs.
     *
     * @return array{0: string|null, 1: string|null}
     */
    private function attribute(?IpAddressRecord $address, CarbonImmutable $at): array
    {
        if (! $address instanceof IpAddressRecord) {
            return [null, null];
        }

        $assignment = IpAssignment::query()
            ->where('ip_address_id', $address->id)
            ->where('assigned_at', '<=', $at)
            ->where(static fn ($query) => $query
                ->whereNull('released_at')
                ->orWhere('released_at', '>=', $at))
            ->latest('assigned_at')
            ->first();

        if (! $assignment instanceof IpAssignment) {
            return [null, null];
        }

        // A service knows its customer; a server does not have one, and that
        // is a real answer rather than a gap — an attack on a machine of ours
        // is not an attack on anybody's account.
        if ($assignment->holder_type !== Service::class) {
            return [null, null];
        }

        $service = Service::query()
            ->withoutGlobalScope('organization')
            ->whereKey($assignment->holder_id)
            ->first();

        return $service instanceof Service
            ? [$service->customer_id, $service->id]
            : [null, null];
    }

    /**
     * The address as this platform spells it, or as the vendor sent it.
     *
     * Two spellings of one IPv6 address must not become two strings on two
     * rows; an address this platform cannot parse at all is stored verbatim,
     * because an unreadable target is still a record of an attack.
     */
    private function canonical(string $target): string
    {
        return $this->parse($target)?->text() ?? substr($target, 0, 45);
    }

    private function parse(string $target): ?IpAddress
    {
        try {
            return IpAddress::parse(trim($target));
        } catch (InvalidAddress) {
            return null;
        }
    }
}
