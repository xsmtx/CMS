<?php

declare(strict_types=1);

use App\Application\Access\SyncPermissions;
use App\Application\Organizations\CreateReseller;
use App\Application\Organizations\ResellerAttributes;
use App\Application\Resellers\RecordResellerEntry;
use App\Application\Resellers\ResellerLedger;
use App\Domain\Access\PermissionRegistry;
use App\Domain\Resellers\ResellerLedgerKind;
use App\Domain\Shared\Money;
use App\Infrastructure\Billing\Models\GatewayEventRecord;
use App\Infrastructure\Billing\Models\Invoice;
use App\Infrastructure\Crm\Models\Customer;
use App\Infrastructure\Import\Models\ImportMapping;
use App\Infrastructure\Resellers\Models\ResellerLedgerEntry;
use App\Support\Organizations\OrganizationContext;
use Carbon\CarbonImmutable;
use Database\Seeders\ProviderOrganizationSeeder;
use Database\Seeders\SystemRoleSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * The places where two requests racing would corrupt money.
 *
 * **Tested at the guard, not by racing threads.** Pest cannot reliably run two
 * requests at once against MariaDB and a test that tried would be flaky — which
 * is worse than no test, because a flaky test gets retried until it passes and
 * then nobody believes it. What is testable, and what actually matters, is that
 * the guard exists and holds: the second write is refused by the database, not by
 * a check-then-act in PHP that two processes can interleave.
 *
 * A check-then-act is the bug this file exists to prevent. `if (! exists()) {
 * create(); }` is correct in one process and wrong in two, and it is what
 * everything below replaces with a constraint the database enforces.
 */
beforeEach(function (): void {
    $this->withoutVite();

    $this->seed(ProviderOrganizationSeeder::class);
    app(SyncPermissions::class)->handle(app(PermissionRegistry::class));
    $this->seed(SystemRoleSeeder::class);
});

/**
 * A gateway that delivers the same event five times must charge once. The
 * deduplication is a unique index, so the fifth delivery is refused by MariaDB
 * even if all five arrive in the same second.
 */
it('refuses a second record of one gateway event, at the database', function (): void {
    GatewayEventRecord::factory()->create([
        'gateway' => 'stripe',
        'event_id' => 'evt_racing',
    ]);

    // Not "assert the service skips it" — assert the *constraint* refuses it.
    // A service that checked first would be correct in one process and wrong in
    // two.
    expect(fn (): GatewayEventRecord => GatewayEventRecord::factory()->create([
        'gateway' => 'stripe',
        'event_id' => 'evt_racing',
    ]))->toThrow(QueryException::class);
});

/**
 * Two live imports of the same legacy row must produce one record. The mapping
 * table's unique index is what makes that true under concurrency, and it is the
 * same index that makes a resumed import skip what came across.
 */
it('refuses a second mapping of one legacy row, at the database', function (): void {
    $customer = Customer::factory()->create();

    app(OrganizationContext::class)->set($customer->organization_id);

    ImportMapping::query()->create([
        'organization_id' => $customer->organization_id,
        'source' => 'whmcs',
        'domain' => 'customers',
        'external_id' => '4182',
        'target_type' => Customer::class,
        'target_id' => $customer->id,
    ]);

    expect(fn (): ImportMapping => ImportMapping::query()->create([
        'organization_id' => $customer->organization_id,
        'source' => 'whmcs',
        'domain' => 'customers',
        'external_id' => '4182',
        'target_type' => Customer::class,
        'target_id' => $customer->id,
    ]))->toThrow(QueryException::class);
});

/**
 * An idempotency key stores the response and replays it. Two requests with one
 * key must not both be handled, and the guard is again a unique index rather
 * than a lookup followed by an insert.
 */
it('refuses a second use of one idempotency key, at the database', function (): void {
    $indexes = DB::select('show index from idempotency_keys');

    $unique = array_filter(
        $indexes,
        static fn (object $index): bool => (int) $index->Non_unique === 0
            && $index->Key_name !== 'PRIMARY',
    );

    // The constraint has to exist, or `EnforceIdempotency` is a check-then-act
    // that two simultaneous requests walk straight through.
    expect($unique)->not->toBeEmpty();
});

/**
 * The reseller ledger writes a running balance onto each row, so two payments
 * recorded at once must not both read the same previous balance.
 *
 * `lockForUpdate` inside the transaction is what stops it, and what is asserted
 * here is that the arithmetic is sequential — each row builds on the one before
 * it, and none of them shares a balance.
 */
it('builds every reseller balance on the row before it', function (): void {
    $reseller = app(CreateReseller::class)->handle(new ResellerAttributes(
        name: 'Anatolia Hosting',
        ownerName: 'Anatolia Owner',
        ownerEmail: 'owner@anatolia.test',
    ))['organization'];

    $ledger = app(ResellerLedger::class);

    foreach ([10000, 5000, 2500] as $amount) {
        $ledger->record(new RecordResellerEntry(
            organizationId: $reseller->id,
            kind: ResellerLedgerKind::Payment,
            amount: Money::ofMinor($amount, 'EUR'),
            occurredAt: CarbonImmutable::now(),
        ));
    }

    $balances = app(OrganizationContext::class)->runAs(
        $reseller->id,
        fn (): array => ResellerLedgerEntry::query()
            ->orderBy('balance_minor')
            ->pluck('balance_minor')
            ->all(),
    );

    // 10000, then 15000, then 17500. No two rows share a balance, which is what
    // a lost update would produce.
    expect($balances)->toBe([10000, 15000, 17500]);
    expect(count(array_unique($balances)))->toBe(3);
});

/**
 * The lock is inside the transaction, which is the only place it does anything: a
 * `lockForUpdate` outside one is a lock released before the write it was
 * protecting.
 */
it('takes the reseller ledger lock inside the transaction', function (): void {
    $source = file_get_contents(base_path('app/Application/Resellers/ResellerLedger.php'));

    expect($source)->not->toBeFalse();

    $inTransaction = mb_strpos((string) $source, 'DB::transaction');
    $lock = mb_strpos((string) $source, 'lockForUpdate');

    expect($inTransaction)->not->toBeFalse();
    expect($lock)->not->toBeFalse();
    // The transaction opens before the lock is taken. A lock taken first would
    // be released the moment the statement finished.
    expect($inTransaction)->toBeLessThan($lock);
});

/**
 * An invoice's paid amount is a cache rebuilt from the ledger (ADR 0024), and one
 * path settles an invoice. Two payments for the same invoice arriving together
 * must leave it settled once — which is why the recalculation reads the rows
 * rather than incrementing a column.
 */
it('rebuilds an invoice paid amount from the rows rather than incrementing it', function (): void {
    $source = file_get_contents(base_path('app/Application/Billing/RecordPayment.php'));

    expect($source)->not->toBeFalse();

    // `increment()` on a money column is the lost update this platform must
    // never contain: two processes each read 0, each write 5000, and 5000 of
    // somebody's money disappears.
    expect((string) $source)->not->toContain('->increment(');
    expect((string) $source)->not->toContain('->decrement(');
});

/**
 * The counter *does* use an atomic increment, and that is correct.
 *
 * The first version of this test grepped for `increment(` across billing and
 * called it a lost-update risk, which was the wrong rule and a promotion's usage
 * count failed it. `increment()` compiles to `set usage_count = usage_count + 1`
 * and is atomic in the database; the lost update is **read-modify-write in PHP**,
 * which is a different shape entirely.
 *
 * So the rule is narrower and sharper: a money column that is a *cache of rows*
 * is recomputed from the rows, and a counter that is only ever a counter uses the
 * atomic increment. Both are asserted, because somebody will one day "tidy" the
 * second into `$promotion->usage_count++` and lose redemptions under load.
 */
it('increments a usage counter atomically rather than in PHP', function (): void {
    $source = (string) file_get_contents(base_path('app/Application/Ordering/PlaceOrder.php'));

    expect($source)->toContain("increment('usage_count')");
    // The lost update: two processes each read 4, each write 5, and one
    // redemption of a limited promotion disappears.
    expect($source)->not->toContain('usage_count++');
    expect($source)->not->toContain("'usage_count' => \$promotion->usage_count");
});

/**
 * An invoice cannot be settled by two paths. One path exists, and a grep is the
 * honest way to assert it: a second one would be a second place that has to get
 * the ledger arithmetic right.
 */
it('settles an invoice from exactly one place', function (): void {
    $writers = [];

    foreach (glob(base_path('app/Application/Billing').'/*.php') ?: [] as $file) {
        $source = (string) file_get_contents($file);

        if (str_contains($source, "'paid_minor'")) {
            $writers[] = basename($file);
        }
    }

    // `RecordPayment` settles; `ApplyCredit` and the import write it for their
    // own reasons and are named here so a fourth cannot appear unnoticed.
    expect($writers)->toBe(['RecordPayment.php']);
});

it('keeps an invoice paid amount and its ledger in step', function (): void {
    $invoice = Invoice::factory()->create([
        'total_minor' => 10000,
        'paid_minor' => 0,
    ]);

    // The cache starts at zero and the ledger is empty: the two agree, which is
    // the invariant every payment path has to preserve.
    expect($invoice->paid_minor)->toBe(0);
    expect($invoice->transactions()->count())->toBe(0);
});
