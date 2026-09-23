<?php

declare(strict_types=1);

namespace App\Application\Resellers;

use App\Domain\Resellers\ResellerLedgerKind;
use App\Domain\Shared\Money;
use Carbon\CarbonImmutable;

/**
 * One movement somebody is asking to record on a reseller's account.
 *
 * A value object rather than eight arguments, for the reason the rest of this
 * layer uses them: the call site reads as a sentence, and a new field cannot
 * be silently dropped by a caller that was written before it existed.
 *
 * `occurredAt` is separate from "now" on purpose. A provider entering last
 * week's bank transfer is recording when the money moved, and a ledger that
 * stamped it today would put the statement in the wrong order.
 */
final readonly class RecordResellerEntry
{
    public function __construct(
        public string $organizationId,
        public ResellerLedgerKind $kind,
        public Money $amount,
        public ?CarbonImmutable $occurredAt = null,
        public ?string $description = null,
        /** Who recorded it, as words. Never an id: a statement is read by people. */
        public ?string $recordedBy = null,
        public ?string $orderId = null,
        public ?string $invoiceId = null,
    ) {}
}
