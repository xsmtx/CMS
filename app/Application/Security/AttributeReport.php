<?php

declare(strict_types=1);

namespace App\Application\Security;

use App\Domain\Network\IpAddress;
use App\Infrastructure\Domains\Models\Domain;
use App\Infrastructure\Network\Models\IpAddressRecord;
use App\Infrastructure\Network\Models\IpAssignment;
use App\Infrastructure\Provisioning\Models\Service;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * Report → IP → Domain → Account → Service → Customer (§13).
 *
 * **The chain is walked at the moment the complaint is about, not now.** A
 * report about an address last Tuesday belongs to whoever held it last
 * Tuesday; attributing it to today's holder is how an innocent customer is
 * suspended for somebody else's spam, and it is the single worst mistake this
 * family can make. `ip_assignments` is append-only for exactly this (Phase C
 * §5).
 *
 * **This is the one place that answers it.** `RecordDdosEvent` grew the same
 * walk privately in Phase C and now calls this instead — two copies of "who
 * held this address then" would eventually disagree about the boundary case
 * that matters, which is an assignment released at the very second of the
 * report. The rule `ResolveSeller` already states, applied to an address.
 *
 * **An address is matched on its bytes, never on its text.** `2001:db8::1`
 * and `2001:0db8:0000:0000:0000:0000:0000:0001` are one address written
 * twice, and a text comparison would attribute neither — the bug that makes
 * an abuse report unanswerable.
 *
 * **Nothing here refuses.** An unattributable report is an `Attribution` full
 * of nulls, and the screen says so in words.
 */
final readonly class AttributeReport
{
    /**
     * @param  'ip'|'domain'|null  $kind
     */
    public function handle(string $organizationId, ?string $kind, ?string $value, CarbonImmutable $at): Attribution
    {
        if ($kind === null || $value === null || trim($value) === '') {
            return Attribution::unattributed();
        }

        return match ($kind) {
            'ip' => $this->atAddress($organizationId, trim($value), $at),
            'domain' => $this->forDomain(trim($value)),
            default => Attribution::unattributed(),
        };
    }

    /**
     * Who held this address at that moment.
     *
     * The assignment that was open then: an `assigned_at` in the past and a
     * `released_at` that is either null or later. A service knows its
     * customer; a server does not have one, and that is a real answer rather
     * than a gap — traffic from a machine of ours is not any account's doing.
     */
    public function atAddress(string $organizationId, string $value, CarbonImmutable $at): Attribution
    {
        $parsed = $this->parse($value);

        if (! $parsed instanceof IpAddress) {
            return Attribution::unattributed();
        }

        $record = IpAddressRecord::query()
            ->where('organization_id', $organizationId)
            ->where('address_bytes', $parsed->bytes)
            ->first();

        if (! $record instanceof IpAddressRecord) {
            return Attribution::unattributed();
        }

        $assignment = IpAssignment::query()
            ->where('ip_address_id', $record->id)
            ->where('assigned_at', '<=', $at)
            ->where(static fn ($query) => $query
                ->whereNull('released_at')
                ->orWhere('released_at', '>=', $at))
            ->latest('assigned_at')
            ->first();

        if (! $assignment instanceof IpAssignment || $assignment->holder_type !== Service::class) {
            return Attribution::unattributed();
        }

        $service = Service::query()
            ->withoutGlobalScope('organization')
            ->whereKey($assignment->holder_id)
            ->first();

        return $service instanceof Service
            ? new Attribution(customerId: $service->customer_id, serviceId: $service->id)
            : Attribution::unattributed();
    }

    /**
     * Whose domain this is.
     *
     * A name this installation does not hold answers nothing rather than
     * guessing at the nearest match: a complaint about `example.com` is not a
     * complaint about `notexample.com`, and a fuzzy match here suspends the
     * wrong customer.
     */
    private function forDomain(string $value): Attribution
    {
        $domain = Domain::query()
            ->whereRaw('LOWER(name) = ?', [mb_strtolower($value)])
            ->first();

        return $domain instanceof Domain
            ? new Attribution(customerId: $domain->customer_id, domainId: $domain->id)
            : Attribution::unattributed();
    }

    /**
     * A value object or nothing. A complainant's typo is not an exception.
     */
    private function parse(string $value): ?IpAddress
    {
        try {
            return IpAddress::parse($value);
        } catch (InvalidArgumentException) {
            return null;
        }
    }
}
