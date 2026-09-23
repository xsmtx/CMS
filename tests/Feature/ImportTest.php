<?php

declare(strict_types=1);

use App\Application\Access\SyncPermissions;
use App\Application\Import\ImportRefused;
use App\Application\Import\RunImport;
use App\Application\Import\StartImport;
use App\Domain\Access\PermissionRegistry;
use App\Domain\Access\SystemRole;
use App\Domain\Billing\InvoiceStatus;
use App\Domain\Catalog\BillingCycle;
use App\Domain\Import\ImportDomain;
use App\Domain\Import\ImportMode;
use App\Domain\Import\ImportOutcome;
use App\Domain\Import\ImportStatus;
use App\Domain\Provisioning\ServiceStatus;
use App\Domain\Support\TicketStatus;
use App\Infrastructure\Billing\Models\Invoice;
use App\Infrastructure\Billing\Models\Transaction;
use App\Infrastructure\Catalog\Models\Product;
use App\Infrastructure\Crm\Models\Customer;
use App\Infrastructure\Domains\Models\Domain as DomainRecord;
use App\Infrastructure\Identity\Models\Contact;
use App\Infrastructure\Identity\Models\StaffUser;
use App\Infrastructure\Import\Models\ImportItem;
use App\Infrastructure\Import\Models\ImportMapping;
use App\Infrastructure\Import\Models\ImportRun;
use App\Infrastructure\Organizations\Models\Organization;
use App\Infrastructure\Provisioning\Models\Service;
use App\Infrastructure\Support\Models\Ticket;
use App\Support\Organizations\OrganizationContext;
use Database\Seeders\ProviderOrganizationSeeder;
use Database\Seeders\SystemRoleSeeder;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Tests\Support\FakeImportSource;

/**
 * Bringing a legacy system across.
 *
 * The decision the whole phase rests on is under test in the first block:
 * **an import writes rows and dispatches nothing.** It is a copy of history,
 * not a set of new business events — `IssueInvoice` would allocate a fresh
 * number and lose the one the customer has on paper, and `OrderPaid` for two
 * years of history would provision two years of services and email everybody.
 *
 * Everything after that is the handoff's own list: resumable, deterministic
 * mapping, duplicate protection, a dry run, detailed errors, and no silent data
 * loss.
 */
beforeEach(function (): void {
    $this->withoutVite();

    $this->seed(ProviderOrganizationSeeder::class);
    app(SyncPermissions::class)->handle(app(PermissionRegistry::class));
    $this->seed(SystemRoleSeeder::class);

    $this->operator = StaffUser::factory()->create();
    $this->operator->assignRole(SystemRole::SuperAdmin);
    $this->operator = $this->operator->fresh();

    // The boundary an operator's session would have set. These tests call the
    // use cases directly, so it is set here — an import has to land in a
    // specific organization and `BelongsToOrganization` refuses to guess.
    app(OrganizationContext::class)->set($this->operator->organization_id);

    $this->source = new FakeImportSource;
});

/** A legacy client, shaped the way WHMCS shapes one. */
function legacyClient(int $id, array $overrides = []): array
{
    return [
        'id' => $id,
        'firstname' => 'Ada',
        'lastname' => 'Lovelace',
        'companyname' => 'Analytical Engines Ltd',
        'email' => "client{$id}@example.test",
        'status' => 'Active',
        'currency' => 'EUR',
        'label' => 'Analytical Engines Ltd',
        ...$overrides,
    ];
}

function runFor(FakeImportSource $source, array $domains, ImportMode $mode = ImportMode::Live): ImportRun
{
    return ImportRun::factory()->create([
        'source' => $source->key(),
        'mode' => $mode->value,
        'status' => ImportStatus::Pending->value,
        'domains' => array_map(static fn (ImportDomain $d): string => $d->value, $domains),
    ]);
}

// --- the central decision ---------------------------------------------------

/**
 * The reason no use case is called. An import that dispatched its events would
 * provision two years of services and email every customer a receipt for a
 * payment they made eighteen months ago.
 */
it('imports history without dispatching a single job or notification', function (): void {
    Queue::fake();
    Notification::fake();

    $this->source
        ->add(ImportDomain::Customers, legacyClient(1))
        ->add(ImportDomain::Invoices, [
            'id' => 50,
            'invoicenum' => 'LEGACY-2024-0001',
            'userid' => 1,
            'date' => '2024-03-01',
            'duedate' => '2024-03-15',
            'datepaid' => '2024-03-02',
            'subtotal' => '100.00',
            'tax' => '20.00',
            'total' => '120.00',
            'credit' => '120.00',
            'status' => 'Paid',
        ])
        ->add(ImportDomain::Transactions, [
            'id' => 900,
            'userid' => 1,
            'invoiceid' => 50,
            'gateway' => 'stripe',
            'date' => '2024-03-02',
            'amountin' => '120.00',
            'amountout' => '0.00',
            'transid' => 'ch_legacy',
        ]);

    $run = runFor($this->source, [
        ImportDomain::Customers,
        ImportDomain::Invoices,
        ImportDomain::Transactions,
    ]);

    app(RunImport::class)->handle($run, $this->source);

    Queue::assertNothingPushed();
    Notification::assertNothingSent();

    $invoice = app(OrganizationContext::class)->withoutBoundary(
        fn (): Invoice => Invoice::query()->withoutGlobalScope('organization')->sole(),
    );

    // The legacy number, kept. This is what a use case would have taken away.
    expect($invoice->number)->toBe('LEGACY-2024-0001');
    expect($invoice->issued_on->toDateString())->toBe('2024-03-01');
    expect($invoice->status)->toBe(InvoiceStatus::Paid);
    // The legacy system's word on what was paid, not a rebuilt cache.
    expect($invoice->paid_minor)->toBe(12000);
});

/**
 * A customer is an organization of its own here. An importer that wrote only the
 * `customers` row would leave every subsequent record in the provider's own
 * subtree, and the boundary would be quietly wrong for the imported half of the
 * installation.
 */
it('gives every imported customer an organization of its own', function (): void {
    $this->source
        ->add(ImportDomain::Customers, legacyClient(1))
        ->add(ImportDomain::Customers, legacyClient(2, ['companyname' => 'Babbage & Co']));

    app(RunImport::class)->handle(runFor($this->source, [ImportDomain::Customers]), $this->source);

    $context = app(OrganizationContext::class);

    $customers = $context->withoutBoundary(
        fn (): array => Customer::query()->withoutGlobalScope('organization')->get()->all(),
    );

    expect($customers)->toHaveCount(2);

    foreach ($customers as $customer) {
        $organization = $context->withoutBoundary(
            fn (): ?Organization => Organization::query()
                ->withoutGlobalScope('organization')
                ->find($customer->organization_id),
        );

        expect($organization?->type->value)->toBe('customer');
        expect($customer->organization_id)->not->toBe($organization?->parent_id);
    }
});

// --- idempotency and resumability -------------------------------------------

/**
 * Duplicate protection and resumability are the same index. Run it twice and
 * the second changes nothing — the property every run in this platform needs.
 */
it('imports nothing the second time it is run', function (): void {
    $this->source->add(ImportDomain::Customers, legacyClient(1));

    app(RunImport::class)->handle(runFor($this->source, [ImportDomain::Customers]), $this->source);

    $second = runFor($this->source, [ImportDomain::Customers]);
    app(RunImport::class)->handle($second, $this->source);

    expect($second->fresh()->totals['customers'][ImportOutcome::Skipped->value])->toBe(1);
    expect($second->fresh()->totals['customers'][ImportOutcome::Created->value])->toBe(0);

    expect(app(OrganizationContext::class)->withoutBoundary(
        fn (): int => Customer::query()->withoutGlobalScope('organization')->count(),
    ))->toBe(1);
});

it('records a mapping for every row it wrote', function (): void {
    $this->source
        ->add(ImportDomain::Customers, legacyClient(1))
        ->add(ImportDomain::Customers, legacyClient(2));

    app(RunImport::class)->handle(runFor($this->source, [ImportDomain::Customers]), $this->source);

    $mappings = ImportMapping::query()->get();

    expect($mappings)->toHaveCount(2);
    expect($mappings->pluck('external_id')->sort()->values()->all())->toBe(['1', '2']);
    expect($mappings->first()->target_type)->toBe(Customer::class);
});

/**
 * A run that died halfway resumes by skipping what is mapped. Simulated by
 * importing one row, then running again with two.
 */
it('resumes by skipping what already came across', function (): void {
    $this->source->add(ImportDomain::Customers, legacyClient(1));

    app(RunImport::class)->handle(runFor($this->source, [ImportDomain::Customers]), $this->source);

    $this->source->add(ImportDomain::Customers, legacyClient(2));

    $second = runFor($this->source, [ImportDomain::Customers]);
    app(RunImport::class)->handle($second, $this->source);

    $totals = $second->fresh()->totals['customers'];

    expect($totals[ImportOutcome::Created->value])->toBe(1);
    expect($totals[ImportOutcome::Skipped->value])->toBe(1);
});

// --- the dry run ------------------------------------------------------------

/**
 * The same code path with one flag. A dry run that took a different path would
 * be a dry run that proves nothing.
 */
it('writes nothing on a dry run, and reports what it would have written', function (): void {
    $this->source
        ->add(ImportDomain::Customers, legacyClient(1))
        ->add(ImportDomain::Customers, legacyClient(2));

    $run = runFor($this->source, [ImportDomain::Customers], ImportMode::DryRun);

    app(RunImport::class)->handle($run, $this->source);

    expect($run->fresh()->totals['customers'][ImportOutcome::Created->value])->toBe(2);

    // Nothing written, and nothing mapped — a dry run that left mappings would
    // make the real import skip every row.
    expect(app(OrganizationContext::class)->withoutBoundary(
        fn (): int => Customer::query()->withoutGlobalScope('organization')->count(),
    ))->toBe(0);
    expect(ImportMapping::query()->count())->toBe(0);
});

// --- detailed errors, and no silent data loss -------------------------------

/**
 * One row failing never stops the run, and the failure is recorded with the
 * external id, a label somebody recognises and a reason they can act on.
 */
it('keeps going past a bad row and says which one it was', function (): void {
    $this->source
        ->add(ImportDomain::Customers, legacyClient(1))
        ->add(ImportDomain::Customers, legacyClient(2, [
            'currency' => 'XYZ',
            'label' => 'Broken Currency Ltd',
        ]))
        ->add(ImportDomain::Customers, legacyClient(3));

    $run = runFor($this->source, [ImportDomain::Customers]);

    app(RunImport::class)->handle($run, $this->source);

    $totals = $run->fresh()->totals['customers'];

    expect($totals[ImportOutcome::Created->value])->toBe(2);
    expect($totals[ImportOutcome::Failed->value])->toBe(1);

    // `completed`, not `failed`: the run finished. The report is what says
    // what to do next.
    expect($run->fresh()->status)->toBe(ImportStatus::Completed);

    $failure = ImportItem::query()->where('outcome', ImportOutcome::Failed->value)->sole();

    expect($failure->external_id)->toBe('2');
    expect($failure->label)->toBe('Broken Currency Ltd');
    // Something an operator can act on, rather than an exception class.
    expect($failure->message)->toContain('XYZ');
});

it('keeps an item row for every legacy row, not just the failures', function (): void {
    $this->source
        ->add(ImportDomain::Customers, legacyClient(1))
        ->add(ImportDomain::Customers, legacyClient(2));

    $run = runFor($this->source, [ImportDomain::Customers]);

    app(RunImport::class)->handle($run, $this->source);

    // No silent data loss: every row is accounted for by name.
    expect(ImportItem::query()->where('run_id', $run->id)->count())->toBe(2);
});

it('fails the whole run when the legacy database goes away', function (): void {
    $this->source->add(ImportDomain::Customers, legacyClient(1));
    $this->source->failReadOf = ImportDomain::Customers;

    $run = runFor($this->source, [ImportDomain::Customers]);

    app(RunImport::class)->handle($run, $this->source);

    // A run that could not proceed at all, which is a different thing from a
    // run with failures in it.
    expect($run->fresh()->status)->toBe(ImportStatus::Failed);
    expect($run->fresh()->error)->toContain('went away');
});

/**
 * Running a partial import whose parents are absent would produce thousands of
 * orphans, all recorded as failures — and nobody reads twelve thousand
 * identical failures.
 */
it('refuses a domain whose parent has never been imported', function (): void {
    $this->source->add(ImportDomain::Invoices, [
        'id' => 50,
        'userid' => 1,
        'date' => '2024-03-01',
        'total' => '120.00',
        'status' => 'Paid',
    ]);

    $run = runFor($this->source, [ImportDomain::Invoices]);

    app(RunImport::class)->handle($run, $this->source);

    expect($run->fresh()->status)->toBe(ImportStatus::Failed);
    expect($run->fresh()->error)->toContain('customers');
    expect(ImportItem::query()->count())->toBe(0);
});

it('allows a partial import when the parent came across in an earlier run', function (): void {
    $this->source->add(ImportDomain::Customers, legacyClient(1));

    app(RunImport::class)->handle(runFor($this->source, [ImportDomain::Customers]), $this->source);

    $this->source->add(ImportDomain::Invoices, [
        'id' => 50,
        'invoicenum' => 'LEGACY-1',
        'userid' => 1,
        'date' => '2024-03-01',
        'total' => '120.00',
        'subtotal' => '120.00',
        'tax' => '0.00',
        'credit' => '0.00',
        'status' => 'Unpaid',
    ]);

    // The normal case: customers last week, invoices today.
    $second = runFor($this->source, [ImportDomain::Invoices]);

    app(RunImport::class)->handle($second, $this->source);

    expect($second->fresh()->status)->toBe(ImportStatus::Completed);
    expect($second->fresh()->totals['invoices'][ImportOutcome::Created->value])->toBe(1);
});

it('records an orphan child as a failure rather than writing a null parent', function (): void {
    $this->source
        ->add(ImportDomain::Customers, legacyClient(1))
        ->add(ImportDomain::Contacts, [
            'id' => 10,
            // A client that is not in this import.
            'userid' => 999,
            'firstname' => 'Charles',
            'lastname' => 'Babbage',
            'email' => 'charles@example.test',
        ]);

    $run = runFor($this->source, [ImportDomain::Customers, ImportDomain::Contacts]);

    app(RunImport::class)->handle($run, $this->source);

    expect($run->fresh()->totals['contacts'][ImportOutcome::Failed->value])->toBe(1);

    $failure = ImportItem::query()
        ->where('domain', ImportDomain::Contacts->value)
        ->where('outcome', ImportOutcome::Failed->value)
        ->sole();

    expect($failure->message)->toContain('999');
});

// --- the mappers ------------------------------------------------------------

/**
 * A service imported at the wrong cycle bills the customer wrongly forever, so
 * an unrecognised cycle is a failure rather than a guess.
 */
it('refuses a service whose billing cycle it cannot bill', function (): void {
    $this->source
        ->add(ImportDomain::Customers, legacyClient(1))
        ->add(ImportDomain::Services, [
            'id' => 70,
            'userid' => 1,
            'packageid' => 5,
            'domain' => 'example.test',
            'billingcycle' => 'Every Other Tuesday',
            'amount' => '10.00',
            'domainstatus' => 'Active',
            'nextduedate' => '2026-12-01',
        ]);

    $run = runFor($this->source, [ImportDomain::Customers, ImportDomain::Services]);

    app(RunImport::class)->handle($run, $this->source);

    expect($run->fresh()->totals['services'][ImportOutcome::Failed->value])->toBe(1);
    expect(ImportItem::query()
        ->where('domain', ImportDomain::Services->value)
        ->sole()
        ->message)->toContain('Every Other Tuesday');
});

/**
 * The most expensive way to get a migration wrong: the first nightly run after
 * it invoices every customer again for a period the legacy system already
 * billed.
 */
it('stops the renewal sweep re-invoicing an imported service', function (): void {
    $this->source
        ->add(ImportDomain::Customers, legacyClient(1))
        ->add(ImportDomain::Services, [
            'id' => 70,
            'userid' => 1,
            'packageid' => 5,
            'domain' => 'example.test',
            'billingcycle' => 'Annually',
            'amount' => '120.00',
            'domainstatus' => 'Active',
            'regdate' => '2025-12-01',
            'nextduedate' => '2026-12-01',
        ]);

    app(RunImport::class)->handle(
        runFor($this->source, [ImportDomain::Customers, ImportDomain::Services]),
        $this->source,
    );

    $service = app(OrganizationContext::class)->withoutBoundary(
        fn (): Service => Service::query()->withoutGlobalScope('organization')->sole(),
    );

    expect($service->billing_cycle)->toBe(BillingCycle::Annually);
    expect($service->recurring_minor)->toBe(12000);
    expect($service->status)->toBe(ServiceStatus::Active);
    // The guard.
    expect($service->renewal_invoiced_through?->toDateString())->toBe('2026-12-01');
    // Never `provisioning`: the account already exists on somebody's server.
    expect($service->server_id)->toBeNull();
    expect($service->external_id)->toBe('70');
});

/**
 * An imported open ticket arrives two years breached and sits at the top of the
 * support queue on the morning after the migration looking like an emergency.
 */
it('imports every ticket closed, with no invented SLA', function (): void {
    $this->source
        ->add(ImportDomain::Customers, legacyClient(1))
        ->add(ImportDomain::Tickets, [
            'id' => 400,
            'tid' => '123456',
            'userid' => 1,
            'title' => 'Mail not arriving',
            'status' => 'Open',
            'priority' => 'High',
            'date' => '2024-01-05 10:00:00',
            'lastreply' => '2024-01-06 11:00:00',
        ]);

    app(RunImport::class)->handle(
        runFor($this->source, [ImportDomain::Customers, ImportDomain::Tickets]),
        $this->source,
    );

    $ticket = app(OrganizationContext::class)->withoutBoundary(
        fn (): Ticket => Ticket::query()->withoutGlobalScope('organization')->sole(),
    );

    expect($ticket->status)->toBe(TicketStatus::Closed);
    expect($ticket->department_id)->toBeNull();
    expect($ticket->first_response_due_at)->toBeNull();
    expect($ticket->resolution_due_at)->toBeNull();
});

/**
 * A legacy products table holds a zero-filled price column per cycle. Importing
 * them faithfully would create a price of zero for every cycle in every
 * currency — and zero means **free**, not unpriced.
 */
it('imports a product with no prices, and hidden', function (): void {
    $this->source->add(ImportDomain::Products, [
        'id' => 5,
        'name' => 'Starter Hosting',
        'description' => 'One site',
        'type' => 'hostingaccount',
        'gid' => 1,
    ]);

    app(RunImport::class)->handle(runFor($this->source, [ImportDomain::Products]), $this->source);

    $product = Product::query()->sole();

    expect($product->name)->toBe('Starter Hosting');
    expect($product->status->value)->toBe('hidden');
    expect($product->prices()->count())->toBe(0);
    // Never the legacy module: it names servers this installation has not been
    // told about.
    expect($product->provisioning_module)->toBeNull();
});

it('never imports a password or a legacy hash', function (): void {
    $this->source
        ->add(ImportDomain::Customers, legacyClient(1))
        ->add(ImportDomain::Contacts, [
            'id' => 10,
            'userid' => 1,
            'firstname' => 'Ada',
            'lastname' => 'Lovelace',
            'email' => 'ada@example.test',
            'phonenumber' => '+90 555 000 0000',
            'subaccount' => '1',
            // A legacy hash, offered and ignored.
            'password' => '$2y$10$notoursandneverwillbe',
        ]);

    app(RunImport::class)->handle(
        runFor($this->source, [ImportDomain::Customers, ImportDomain::Contacts]),
        $this->source,
    );

    $contact = app(OrganizationContext::class)->withoutBoundary(
        fn (): Contact => Contact::query()->withoutGlobalScope('organization')->sole(),
    );

    expect($contact->email)->toBe('ada@example.test');
    expect($contact->portal_access)->toBeTrue();
    // Something random, reached through the reset flow like every account here.
    expect($contact->password)->not->toContain('notoursandneverwillbe');
});

it('imports a refund as a refund rather than as a negative payment', function (): void {
    $this->source
        ->add(ImportDomain::Customers, legacyClient(1))
        ->add(ImportDomain::Transactions, [
            'id' => 901,
            'userid' => 1,
            'invoiceid' => 0,
            'gateway' => 'stripe',
            'date' => '2024-04-01',
            'amountin' => '0.00',
            'amountout' => '40.00',
            'transid' => 're_legacy',
        ]);

    app(RunImport::class)->handle(
        runFor($this->source, [ImportDomain::Customers, ImportDomain::Transactions]),
        $this->source,
    );

    $transaction = app(OrganizationContext::class)->withoutBoundary(
        fn (): Transaction => Transaction::query()->withoutGlobalScope('organization')->sole(),
    );

    expect($transaction->kind->value)->toBe('refund');
    // Always positive; the kind decides direction (ADR 0024).
    expect($transaction->amount_minor)->toBe(4000);
});

it('splits a domain at the first dot and leaves the registrar alone', function (): void {
    $this->source
        ->add(ImportDomain::Customers, legacyClient(1))
        ->add(ImportDomain::Domains, [
            'id' => 80,
            'userid' => 1,
            'domain' => 'example.co.uk',
            'registrar' => 'enom',
            'status' => 'Active',
            'registrationdate' => '2024-01-01',
            'expirydate' => '2027-01-01',
            'recurringamount' => '12.00',
            'donotrenew' => '0',
        ]);

    app(RunImport::class)->handle(
        runFor($this->source, [ImportDomain::Customers, ImportDomain::Domains]),
        $this->source,
    );

    $domain = app(OrganizationContext::class)->withoutBoundary(
        fn (): DomainRecord => DomainRecord::query()->withoutGlobalScope('organization')->sole(),
    );

    expect($domain->name)->toBe('example.co.uk');
    expect($domain->label)->toBe('example');
    expect($domain->extension)->toBe('co.uk');
    // Never the legacy registrar: a domain attached to an adapter with no
    // matching account at the registry is a renewal that fails at exactly the
    // wrong moment.
    expect($domain->registrar)->toBeNull();
    expect($domain->tld_id)->toBeNull();
    // The same guard the services get.
    expect($domain->renewal_invoiced_through?->toDateString())->toBe('2027-01-01');
});

/**
 * MySQL's zero date is everywhere in a real legacy database, and Carbon parses
 * it into the year zero — which becomes a renewal date two thousand years ago
 * and a dunning sweep that tries very hard.
 */
it('reads a zero date as no date', function (): void {
    $this->source
        ->add(ImportDomain::Customers, legacyClient(1))
        ->add(ImportDomain::Services, [
            'id' => 70,
            'userid' => 1,
            'packageid' => 5,
            'domain' => 'example.test',
            'billingcycle' => 'Monthly',
            'amount' => '10.00',
            'domainstatus' => 'Active',
            'regdate' => '0000-00-00',
            'nextduedate' => '0000-00-00',
        ]);

    app(RunImport::class)->handle(
        runFor($this->source, [ImportDomain::Customers, ImportDomain::Services]),
        $this->source,
    );

    $service = app(OrganizationContext::class)->withoutBoundary(
        fn (): Service => Service::query()->withoutGlobalScope('organization')->sole(),
    );

    expect($service->starts_on)->toBeNull();
    expect($service->next_due_on)->toBeNull();
});

it('refuses a run with no domains', function (): void {
    expect(fn (): ImportRun => app(StartImport::class)->handle(
        'whmcs',
        ImportMode::DryRun,
        [],
    ))->toThrow(ImportRefused::class);
});
