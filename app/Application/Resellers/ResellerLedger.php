<?php

declare(strict_types=1);

namespace App\Application\Resellers;

use App\Application\Resellers\Exceptions\ResellerEntryRefused;
use App\Domain\Organizations\OrganizationType;
use App\Domain\Shared\Money;
use App\Infrastructure\Organizations\Models\Organization;
use App\Infrastructure\Resellers\Models\ResellerLedgerEntry;
use App\Support\Audit\Facades\Audit;
use App\Support\Organizations\OrganizationContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * What a reseller holds with the provider, and what they owe.
 *
 * A ledger rather than a column, for the reason every financial history here
 * is append-only: a balance that was one number is a number somebody can
 * edit, and then nobody can say what it used to be or why it moved.
 *
 * **A positive balance is what the reseller holds.** Negative means they owe
 * the provider. Stated once here because it is the sort of convention that
 * gets read backwards by the third screen to use it, and a reseller shown
 * "−400.00 credit" is a reseller on the telephone.
 *
 * **Balances are per currency and never summed.** A reseller who took a
 * payment in lira and one in euros has two balances, and adding them is the
 * mistake this platform refuses everywhere else.
 *
 * The running balance is written onto the row, so a statement is readable
 * without summing the table and each row records what the balance was at the
 * time. That makes the write order matter, which is why it happens inside a
 * transaction that locks the account's rows first: two payments recorded at
 * once would otherwise both read the same previous balance and the second
 * would overwrite the first's arithmetic.
 */
final readonly class ResellerLedger
{
    public function __construct(private OrganizationContext $organizations) {}

    /**
     * Append one movement and return the row that was written.
     */
    public function record(RecordResellerEntry $entry, ?Model $actor = null): ResellerLedgerEntry
    {
        if (! $entry->amount->isPositive()) {
            // Zero is not a movement and a negative is a caller signing an
            // amount the kind already signs.
            throw ResellerEntryRefused::notPositive();
        }

        $reseller = $this->reseller($entry->organizationId);

        $written = DB::transaction(function () use ($entry, $reseller): ResellerLedgerEntry {
            $previous = $this->lastBalanceMinor(
                $reseller->id,
                $entry->amount->currency->code,
                lock: true,
            );

            $signed = $entry->kind->increasesBalance()
                ? $entry->amount->minorUnits
                : -$entry->amount->minorUnits;

            // Created inside the reseller's own boundary: the row belongs to
            // them, and a provider's staff recording it must not leave it
            // owned by the provider.
            return $this->organizations->runAs(
                $reseller->id,
                fn (): ResellerLedgerEntry => ResellerLedgerEntry::query()->create([
                    'organization_id' => $reseller->id,
                    'kind' => $entry->kind->value,
                    'currency_code' => $entry->amount->currency->code,
                    'amount_minor' => $entry->amount->minorUnits,
                    'balance_minor' => $previous + $signed,
                    'order_id' => $entry->orderId,
                    'invoice_id' => $entry->invoiceId,
                    'description' => $entry->description,
                    'recorded_by' => $entry->recordedBy,
                    'occurred_at' => $entry->occurredAt ?? CarbonImmutable::now(),
                ]),
            );
        });

        Audit::action('organizations.reseller.ledger_recorded')
            ->by($actor)
            ->on($written)
            ->forOrganization($reseller->id)
            ->because($entry->description)
            ->withMetadata([
                'kind' => $entry->kind->value,
                'amount_minor' => $entry->amount->minorUnits,
                'currency' => $entry->amount->currency->code,
                'balance_minor' => $written->balance_minor,
            ])
            ->write();

        return $written;
    }

    /**
     * What the reseller holds, per currency.
     *
     * Read from the newest row of each currency rather than summed: the
     * running balance is the answer, and summing would be a second
     * implementation of the same arithmetic that could disagree with the
     * statement an operator is looking at.
     *
     * @return list<Money>
     */
    public function balances(string $organizationId): array
    {
        $rows = $this->organizations->runAs(
            $organizationId,
            static fn (): array => ResellerLedgerEntry::query()
                ->latest('occurred_at')
                ->orderByDesc('id')
                ->get(['currency_code', 'balance_minor'])
                ->all(),
        );

        $seen = [];

        foreach ($rows as $row) {
            $currency = (string) $row->currency_code;

            // First row wins, because the query is newest first.
            $seen[$currency] ??= Money::ofMinor((int) $row->balance_minor, $currency);
        }

        return array_values($seen);
    }

    /**
     * The statement, newest first.
     *
     * @return list<ResellerLedgerEntry>
     */
    public function statement(string $organizationId, int $limit = 50): array
    {
        return $this->organizations->runAs(
            $organizationId,
            static fn (): array => array_values(ResellerLedgerEntry::query()
                ->latest('occurred_at')
                ->orderByDesc('id')
                ->limit($limit)
                ->get()
                ->all()),
        );
    }

    /**
     * The balance the next row will build on.
     *
     * `lockForUpdate` when writing, and only then: a statement being read
     * has no reason to hold a lock, and a read that did would make two
     * operators looking at the same reseller wait on each other.
     */
    private function lastBalanceMinor(string $organizationId, string $currency, bool $lock): int
    {
        $last = $this->organizations->runAs(
            $organizationId,
            static fn (): ?ResellerLedgerEntry => ResellerLedgerEntry::query()
                ->where('currency_code', $currency)
                ->latest('occurred_at')
                ->orderByDesc('id')
                ->when($lock, static fn ($query) => $query->lockForUpdate())
                ->first(),
        );

        return $last instanceof ResellerLedgerEntry ? $last->balance_minor : 0;
    }

    /**
     * The reseller, or a refusal.
     *
     * Read inside the caller's boundary deliberately: a reseller is a child
     * of the provider, so the provider's staff reach it and another
     * reseller's staff do not — which is the whole isolation, arrived at by
     * the same mechanism as everything else rather than by a clause here.
     */
    private function reseller(string $organizationId): Organization
    {
        $organization = Organization::query()->where('id', $organizationId)->first();

        if (! $organization instanceof Organization) {
            throw ResellerEntryRefused::unknownOrganization($organizationId);
        }

        if ($organization->type !== OrganizationType::Reseller) {
            // A customer's credit is a `transactions` row against their own
            // account; the provider has no account with itself.
            throw ResellerEntryRefused::notAReseller($organization->type);
        }

        return $organization;
    }
}
