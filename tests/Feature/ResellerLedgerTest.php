<?php

declare(strict_types=1);

use App\Application\Access\SyncPermissions;
use App\Application\Organizations\CreateReseller;
use App\Application\Organizations\ResellerAttributes;
use App\Application\Resellers\Exceptions\ResellerEntryRefused;
use App\Application\Resellers\RecordResellerEntry;
use App\Application\Resellers\ResellerLedger;
use App\Domain\Access\PermissionRegistry;
use App\Domain\Resellers\ResellerLedgerKind;
use App\Domain\Shared\Money;
use App\Infrastructure\Audit\Models\AuditLog;
use App\Infrastructure\Identity\Models\StaffUser;
use App\Infrastructure\Organizations\Models\Organization;
use App\Infrastructure\Resellers\Models\ResellerLedgerEntry;
use App\Support\Organizations\OrganizationContext;
use Carbon\CarbonImmutable;
use Database\Seeders\ProviderOrganizationSeeder;
use Database\Seeders\SystemRoleSeeder;

/**
 * What a reseller holds with the provider, and what they owe.
 *
 * A ledger rather than a column, so the tests are the ones every ledger in
 * this platform earns: the amount is always positive and the kind decides
 * direction, the running balance is arithmetic nobody can edit, currencies
 * are never added together, and the row belongs to the reseller rather than
 * to whoever recorded it.
 *
 * The convention under test throughout: **a positive balance is what the
 * reseller holds**, negative means they owe the provider.
 */
beforeEach(function (): void {
    $this->withoutVite();

    $this->seed(ProviderOrganizationSeeder::class);
    app(SyncPermissions::class)->handle(app(PermissionRegistry::class));
    $this->seed(SystemRoleSeeder::class);

    $created = app(CreateReseller::class)->handle(new ResellerAttributes(
        name: 'Anatolia Hosting',
        ownerName: 'Anatolia Owner',
        ownerEmail: 'owner@anatolia.test',
    ));

    $this->reseller = $created['organization'];
    $this->ledger = app(ResellerLedger::class);
});

function entry(string $organizationId, ResellerLedgerKind $kind, int $minor, string $currency = 'EUR'): RecordResellerEntry
{
    return new RecordResellerEntry(
        organizationId: $organizationId,
        kind: $kind,
        amount: Money::ofMinor($minor, $currency),
    );
}

it('adds what the reseller paid and takes what they owe', function (): void {
    $this->ledger->record(entry($this->reseller->id, ResellerLedgerKind::Payment, 50000));
    $this->ledger->record(entry($this->reseller->id, ResellerLedgerKind::Charge, 12000));
    $row = $this->ledger->record(entry($this->reseller->id, ResellerLedgerKind::Credit, 2000));

    // 500.00 in, 120.00 out, 20.00 back: 400.00 held.
    expect($row->balance_minor)->toBe(40000);

    $balances = $this->ledger->balances($this->reseller->id);

    expect($balances)->toHaveCount(1);
    expect($balances[0]->minorUnits)->toBe(40000);
});

/**
 * The whole reason the kind carries the direction: a caller cannot express a
 * movement whose sign disagrees with what it says it is.
 */
it('stores every amount positive and signs it by kind', function (): void {
    $this->ledger->record(entry($this->reseller->id, ResellerLedgerKind::Charge, 7500));

    $row = app(OrganizationContext::class)->runAs(
        $this->reseller->id,
        fn (): ResellerLedgerEntry => ResellerLedgerEntry::query()->sole(),
    );

    expect($row->amount_minor)->toBe(7500);
    expect($row->balance_minor)->toBe(-7500);
    expect($row->signedMinor())->toBe(-7500);
});

it('lets a reseller owe the provider', function (): void {
    $row = $this->ledger->record(entry($this->reseller->id, ResellerLedgerKind::Charge, 9900));

    // Negative is a real state, not an error: the provider has sold something
    // through a reseller who has not paid for it yet.
    expect($row->balance_minor)->toBe(-9900);
});

it('takes back credit with a withdrawal rather than a negative credit', function (): void {
    $this->ledger->record(entry($this->reseller->id, ResellerLedgerKind::Payment, 30000));
    $row = $this->ledger->record(entry($this->reseller->id, ResellerLedgerKind::Withdrawal, 10000));

    expect($row->balance_minor)->toBe(20000);
});

/**
 * Adding lira to euros is the mistake this platform refuses everywhere else,
 * and a reseller with two currencies has two balances.
 */
it('keeps a balance per currency and never sums them', function (): void {
    $this->ledger->record(entry($this->reseller->id, ResellerLedgerKind::Payment, 50000, 'EUR'));
    $this->ledger->record(entry($this->reseller->id, ResellerLedgerKind::Payment, 120000, 'TRY'));
    $this->ledger->record(entry($this->reseller->id, ResellerLedgerKind::Charge, 20000, 'TRY'));

    $balances = collect($this->ledger->balances($this->reseller->id))
        ->mapWithKeys(fn (Money $money): array => [$money->currency->code => $money->minorUnits]);

    expect($balances->all())->toBe(['TRY' => 100000, 'EUR' => 50000]);
});

it('refuses an amount that is not a movement', function (): void {
    expect(fn (): ResellerLedgerEntry => $this->ledger->record(
        entry($this->reseller->id, ResellerLedgerKind::Payment, 0),
    ))->toThrow(ResellerEntryRefused::class);

    expect(fn (): ResellerLedgerEntry => $this->ledger->record(
        entry($this->reseller->id, ResellerLedgerKind::Payment, -500),
    ))->toThrow(ResellerEntryRefused::class);
});

/**
 * A customer's credit is a `transactions` row against their own account, and
 * the provider has no account with itself. An account on the wrong kind of
 * organization is a balance nobody would ever reconcile.
 */
it('refuses an account on anything that is not a reseller', function (): void {
    $provider = app(OrganizationContext::class)->withoutBoundary(
        fn (): string => Organization::provider()
            ->withoutGlobalScope('organization')
            ->orderBy('path')
            ->firstOrFail()
            ->id,
    );

    expect(fn (): ResellerLedgerEntry => $this->ledger->record(
        entry($provider, ResellerLedgerKind::Payment, 1000),
    ))->toThrow(ResellerEntryRefused::class);
});

it('refuses an organization it cannot reach', function (): void {
    expect(fn (): ResellerLedgerEntry => $this->ledger->record(
        entry('01jzzzzzzzzzzzzzzzzzzzzzzz', ResellerLedgerKind::Payment, 1000),
    ))->toThrow(ResellerEntryRefused::class);
});

/**
 * The row belongs to the reseller, not to the provider's staff member who
 * recorded it. That is what makes "a reseller cannot see another reseller's
 * account" true by the same mechanism as everything else.
 */
it('writes the row inside the reseller own boundary', function (): void {
    $this->ledger->record(entry($this->reseller->id, ResellerLedgerKind::Payment, 1000));

    $other = app(CreateReseller::class)->handle(new ResellerAttributes(
        name: 'Aegean Hosting',
        ownerName: 'Aegean Owner',
        ownerEmail: 'owner@aegean.test',
    ));

    $context = app(OrganizationContext::class);

    expect($context->runAs(
        $this->reseller->id,
        fn (): int => ResellerLedgerEntry::query()->count(),
    ))->toBe(1);

    expect($context->runAs(
        $other['organization']->id,
        fn (): int => ResellerLedgerEntry::query()->count(),
    ))->toBe(0);
});

/**
 * The running balance is what the statement reads, so it has to be the
 * balance *at the time* — a row inserted with an earlier `occurred_at` must
 * not rewrite the arithmetic of the rows after it.
 */
it('builds each balance on the row before it, in the order they are recorded', function (): void {
    $first = $this->ledger->record(new RecordResellerEntry(
        organizationId: $this->reseller->id,
        kind: ResellerLedgerKind::Payment,
        amount: Money::ofMinor(10000, 'EUR'),
        occurredAt: CarbonImmutable::parse('2026-09-01 10:00:00'),
    ));

    $second = $this->ledger->record(new RecordResellerEntry(
        organizationId: $this->reseller->id,
        kind: ResellerLedgerKind::Charge,
        amount: Money::ofMinor(4000, 'EUR'),
        occurredAt: CarbonImmutable::parse('2026-09-05 10:00:00'),
    ));

    expect($first->balance_minor)->toBe(10000);
    expect($second->balance_minor)->toBe(6000);

    // Newest first, which is the order a statement is read in.
    $statement = $this->ledger->statement($this->reseller->id);

    expect(array_map(static fn (ResellerLedgerEntry $row): int => $row->balance_minor, $statement))
        ->toBe([6000, 10000]);
});

it('records who moved the money and why', function (): void {
    $staff = StaffUser::factory()->create();

    $row = $this->ledger->record(new RecordResellerEntry(
        organizationId: $this->reseller->id,
        kind: ResellerLedgerKind::Payment,
        amount: Money::ofMinor(25000, 'EUR'),
        description: 'Bank transfer, ref 88120',
        recordedBy: 'Ayşe at the desk',
    ), $staff);

    expect($row->description)->toBe('Bank transfer, ref 88120');
    expect($row->recorded_by)->toBe('Ayşe at the desk');

    $record = AuditLog::query()
        ->where('action', 'organizations.reseller.ledger_recorded')
        ->sole();

    expect($record->reason)->toBe('Bank transfer, ref 88120');
    expect($record->metadata['balance_minor'])->toBe(25000);
});

it('says nothing about a reseller with no movements', function (): void {
    expect($this->ledger->balances($this->reseller->id))->toBe([]);
    expect($this->ledger->statement($this->reseller->id))->toBe([]);
});
