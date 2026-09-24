<?php

declare(strict_types=1);

use App\Application\Access\SyncPermissions;
use App\Application\Automation\Runs\RunDunningSequence;
use App\Application\Billing\ChargeLateFee;
use App\Application\Billing\CreateInvoiceFromOrder;
use App\Application\Billing\IssueInvoice;
use App\Application\Shared\AllocateNumber;
use App\Domain\Access\PermissionRegistry;
use App\Domain\Access\SystemRole;
use App\Domain\Billing\InvoiceStatus;
use App\Domain\Ordering\OrderStatus;
use App\Domain\Organizations\OrganizationType;
use App\Domain\Shared\NumberResetPeriod;
use App\Infrastructure\Automation\Models\DunningStep;
use App\Infrastructure\Automation\Models\InvoiceDunningStep;
use App\Infrastructure\Billing\Models\BillingSetting;
use App\Infrastructure\Billing\Models\Invoice;
use App\Infrastructure\Billing\Models\InvoiceItem;
use App\Infrastructure\Crm\Models\Customer;
use App\Infrastructure\Identity\Models\StaffUser;
use App\Infrastructure\Ordering\Models\Order;
use App\Infrastructure\Ordering\Models\OrderItem;
use App\Infrastructure\Organizations\Models\Organization;
use App\Infrastructure\Shared\Models\NumberSequence;
use App\Support\Organizations\OrganizationContext;
use Carbon\CarbonImmutable;
use Database\Seeders\ProviderOrganizationSeeder;
use Database\Seeders\SystemRoleSeeder;

/**
 * Billing terms: when an invoice falls due, what being late costs, and what a
 * document number looks like.
 *
 * All three were constants in `config/platform.php`, which meant an operator
 * could not change them without an environment file and a deploy, and a reseller
 * in another country could not have their own at all. Each case here is the
 * shape of a real jurisdiction or a real migration rather than a field being
 * exercised.
 */
beforeEach(function (): void {
    $this->withoutVite();

    $this->seed(ProviderOrganizationSeeder::class);
    app(SyncPermissions::class)->handle(app(PermissionRegistry::class));
    $this->seed(SystemRoleSeeder::class);

    $this->provider = Organization::query()
        ->where('type', OrganizationType::Provider->value)
        ->firstOrFail();

    app(OrganizationContext::class)->set($this->provider->id);

    $this->owner = StaffUser::factory()->create();
    $this->owner->assignRole(SystemRole::SuperAdmin);
    $this->owner = $this->owner->fresh();

    $this->administrator = StaffUser::factory()->create();
    $this->administrator->assignRole(SystemRole::Administrator);
    $this->administrator = $this->administrator->fresh();
});

/**
 * The terms form, as the screen posts it.
 *
 * @return array<string, mixed>
 */
function termsForm(array $overrides = []): array
{
    return array_merge([
        'due_days' => 30,
        'late_fee_rate' => '0',
        'late_fee_label' => '',
        'document_note' => '',
    ], $overrides);
}

/**
 * An order ready to be invoiced, so the due date can be read off the result.
 */
function orderForTerms(): Order
{
    $order = Order::factory()->create(['status' => OrderStatus::Pending->value]);

    OrderItem::factory()->forOrder($order)->create();

    return $order->fresh();
}

/**
 * An issued, unpaid invoice with a known amount and a due date in the past.
 */
function overdueInvoiceForTerms(
    int $minor,
    int $paid = 0,
    int $dueDaysAgo = 7,
    ?Customer $customer = null,
): Invoice {
    $customer ??= Customer::factory()->create();

    $invoice = Invoice::factory()->create([
        'customer_id' => $customer->id,
        'organization_id' => $customer->organization_id,
        'status' => InvoiceStatus::Overdue->value,
        'currency_code' => 'EUR',
        'subtotal_minor' => $minor,
        'discount_minor' => 0,
        'tax_minor' => 0,
        'total_minor' => $minor,
        'paid_minor' => $paid,
        'issued_on' => CarbonImmutable::now()->subDays($dueDaysAgo + 14)->toDateString(),
        'due_on' => CarbonImmutable::now()->subDays($dueDaysAgo)->toDateString(),
    ]);

    InvoiceItem::factory()->create([
        'invoice_id' => $invoice->id,
        'organization_id' => $invoice->organization_id,
        'currency_code' => 'EUR',
        'unit_amount_minor' => $minor,
        'line_amount_minor' => $minor,
        'tax_minor' => 0,
        'discount_minor' => 0,
    ]);

    return $invoice->fresh();
}

// ---------------------------------------------------------------------------
// The screen
// ---------------------------------------------------------------------------

it('is the owner\'s screen and nobody else\'s', function (): void {
    $this->actingAs($this->administrator, 'staff')
        ->get('/admin/billing/settings')
        ->assertForbidden();

    $this->actingAs($this->administrator, 'staff')
        ->put('/admin/billing/settings', termsForm())
        ->assertForbidden();

    $this->actingAs($this->owner, 'staff')
        ->get('/admin/billing/settings')
        ->assertOk();
});

it('says the terms are the shipped default until somebody states them', function (): void {
    $this->actingAs($this->owner, 'staff')
        ->get('/admin/billing/settings')
        ->assertOk()
        // A row written on read would be terms nobody agreed to, and would
        // freeze today's configured default where a deploy could not reach it.
        ->assertInertia(fn ($page) => $page
            ->where('settings.stated', false)
            ->where('settings.dueDays', (int) config('platform.billing.due_days'))
        );

    expect(BillingSetting::query()->count())->toBe(0);

    $this->actingAs($this->owner, 'staff')
        ->put('/admin/billing/settings', termsForm(['due_days' => 30]))
        ->assertRedirect();

    $this->actingAs($this->owner, 'staff')
        ->get('/admin/billing/settings')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('settings.stated', true));
});

it('saves the terms into one row however often they are saved', function (): void {
    $this->actingAs($this->owner, 'staff')
        ->put('/admin/billing/settings', termsForm([
            'due_days' => 0,
            'late_fee_rate' => '1.5',
            'late_fee_label' => 'Gecikme bedeli',
            'document_note' => 'Registered in Istanbul, no. 123456.',
        ]))
        ->assertRedirect();

    $settings = BillingSetting::query()->firstOrFail();

    expect($settings->due_days)->toBe(0)
        ->and($settings->late_fee_rate_ppm)->toBe(15_000)
        ->and($settings->lateFeePercentage())->toBe('1.5')
        ->and($settings->late_fee_label)->toBe('Gecikme bedeli')
        ->and($settings->document_note)->toBe('Registered in Istanbul, no. 123456.');

    $this->actingAs($this->owner, 'staff')
        ->put('/admin/billing/settings', termsForm(['due_days' => 45, 'late_fee_rate' => '0']))
        ->assertRedirect();

    expect(BillingSetting::query()->count())->toBe(1)
        ->and(BillingSetting::query()->firstOrFail()->due_days)->toBe(45);
});

it('refuses terms that would silently stop dunning', function (): void {
    // A due date more than a year out is a typo, and a typo here means the
    // unpaid-invoice sequence never fires.
    $this->actingAs($this->owner, 'staff')
        ->put('/admin/billing/settings', termsForm(['due_days' => 4000]))
        ->assertSessionHasErrors('due_days');

    foreach (['1e2', '0x14', 'ten', '-1', '20.123456'] as $rate) {
        $this->actingAs($this->owner, 'staff')
            ->put('/admin/billing/settings', termsForm(['late_fee_rate' => $rate]))
            ->assertSessionHasErrors('late_fee_rate');
    }

    expect(BillingSetting::query()->count())->toBe(0);
});

// ---------------------------------------------------------------------------
// Payment terms
// ---------------------------------------------------------------------------

it('dates an invoice by the seller\'s terms, not by configuration', function (): void {
    BillingSetting::factory()->forOrganization($this->provider->id)->dueIn(30)->create();

    $order = orderForTerms();

    $invoice = app(CreateInvoiceFromOrder::class)->handle($order);

    expect($invoice->due_on?->toDateString())
        ->toBe(CarbonImmutable::now()->addDays(30)->toDateString());
});

it('falls back to the configured default when a seller has stated nothing', function (): void {
    $order = orderForTerms();

    $invoice = app(CreateInvoiceFromOrder::class)->handle($order);

    expect($invoice->due_on?->toDateString())->toBe(
        CarbonImmutable::now()->addDays((int) config('platform.billing.due_days'))->toDateString(),
    );
});

it('copies the document note onto the invoice when it is issued', function (): void {
    BillingSetting::factory()
        ->forOrganization($this->provider->id)
        ->create(['document_note' => 'Registered in Istanbul, no. 123456.']);

    $first = app(CreateInvoiceFromOrder::class)->handle(orderForTerms());
    app(IssueInvoice::class)->handle($first);

    expect($first->fresh()?->terms)->toBe('Registered in Istanbul, no. 123456.');

    // Changing the note changes the next document and nothing already issued: the
    // wording a country obliges an invoice to carry is part of what the customer
    // received (ADR 0023).
    BillingSetting::query()->update(['document_note' => 'Something else entirely.']);

    $second = app(CreateInvoiceFromOrder::class)->handle(orderForTerms());
    app(IssueInvoice::class)->handle($second);

    expect($first->fresh()?->terms)->toBe('Registered in Istanbul, no. 123456.')
        ->and($second->fresh()?->terms)->toBe('Something else entirely.');
});

it('leaves a note an operator typed onto one document alone', function (): void {
    BillingSetting::factory()
        ->forOrganization($this->provider->id)
        ->create(['document_note' => 'The standard wording.']);

    $invoice = app(CreateInvoiceFromOrder::class)->handle(orderForTerms());
    $invoice->forceFill(['terms' => 'Agreed with this customer in writing.'])->save();

    app(IssueInvoice::class)->handle($invoice);

    expect($invoice->fresh()?->terms)->toBe('Agreed with this customer in writing.');
});

// ---------------------------------------------------------------------------
// Numbering
// ---------------------------------------------------------------------------

it('restarts the sequence with the year when asked to', function (): void {
    $numbers = app(AllocateNumber::class);

    CarbonImmutable::setTestNow(new CarbonImmutable('2026-12-30 10:00:00'));

    expect($numbers->handle($this->provider->id, 'invoice', 'INV-'))->toBe('INV-000001');
    expect($numbers->handle($this->provider->id, 'invoice', 'INV-'))->toBe('INV-000002');

    NumberSequence::query()->where('key', 'invoice')->update([
        'reset_period' => NumberResetPeriod::Yearly->value,
    ]);

    // Turning the reset on does not renumber this year's documents: the row has
    // no period yet, so the next allocation adopts 2026 rather than restarting.
    expect($numbers->handle($this->provider->id, 'invoice', 'INV-'))->toBe('INV-000003');

    CarbonImmutable::setTestNow(new CarbonImmutable('2027-01-02 09:00:00'));

    expect($numbers->handle($this->provider->id, 'invoice', 'INV-'))->toBe('INV-000001');
    expect($numbers->handle($this->provider->id, 'invoice', 'INV-'))->toBe('INV-000002');

    // And not again in the same year.
    CarbonImmutable::setTestNow(new CarbonImmutable('2027-06-01 09:00:00'));
    expect($numbers->handle($this->provider->id, 'invoice', 'INV-'))->toBe('INV-000003');

    CarbonImmutable::setTestNow();
});

it('restarts monthly, and never when it was not asked to', function (): void {
    $numbers = app(AllocateNumber::class);

    CarbonImmutable::setTestNow(new CarbonImmutable('2026-03-31 10:00:00'));

    $numbers->handle($this->provider->id, 'invoice', 'INV-');

    NumberSequence::query()->where('key', 'invoice')->update([
        'reset_period' => NumberResetPeriod::Monthly->value,
    ]);

    $numbers->handle($this->provider->id, 'invoice', 'INV-');

    CarbonImmutable::setTestNow(new CarbonImmutable('2026-04-01 10:00:00'));
    expect($numbers->handle($this->provider->id, 'invoice', 'INV-'))->toBe('INV-000001');

    // Switched off again, the stale key must not cause one more restart.
    NumberSequence::query()->where('key', 'invoice')->update([
        'reset_period' => NumberResetPeriod::Never->value,
    ]);

    CarbonImmutable::setTestNow(new CarbonImmutable('2026-05-01 10:00:00'));
    expect($numbers->handle($this->provider->id, 'invoice', 'INV-'))->toBe('INV-000002');

    CarbonImmutable::setTestNow();
});

it('lets an operator continue the book they are migrating', function (): void {
    // The reason `next_value` is writable at all: an installation taking over
    // from another panel has invoices on paper up to INV-010420, and starting
    // again at one collides with every one of them.
    $this->actingAs($this->owner, 'staff')
        ->put('/admin/billing/numbering/invoice', [
            'prefix' => '2026/',
            'padding' => 5,
            'next_value' => 10_421,
            'reset_period' => NumberResetPeriod::Yearly->value,
        ])
        ->assertRedirect();

    expect(app(AllocateNumber::class)->handle($this->provider->id, 'invoice', 'INV-'))
        ->toBe('2026/10421');

    // And the screen shows the next one through the same formatter.
    $this->actingAs($this->owner, 'staff')
        ->get('/admin/billing/settings')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('sequences.1.preview', '2026/10422'));
});

it('refuses a numbering key that is not a financial document', function (): void {
    // `ticket` is a sequence and is not a financial document. A screen that
    // accepted any key would let somebody renumber support tickets from the
    // billing screen.
    $this->actingAs($this->owner, 'staff')
        ->put('/admin/billing/numbering/ticket', [
            'prefix' => 'X-',
            'padding' => 4,
            'next_value' => 1,
            'reset_period' => 'never',
        ])
        ->assertNotFound();
});

// ---------------------------------------------------------------------------
// The late fee
// ---------------------------------------------------------------------------

it('charges a late fee as its own invoice, never as a line on a frozen one', function (): void {
    BillingSetting::factory()->forOrganization($this->provider->id)->lateFee('10')->create();

    $overdue = overdueInvoiceForTerms(10_000);
    $before = [$overdue->subtotal_minor, $overdue->total_minor, $overdue->items()->count()];

    $fee = app(ChargeLateFee::class)->handle($overdue);

    expect($fee)->not->toBeNull()
        ->and($fee->id)->not->toBe($overdue->id)
        ->and($fee->total_minor)->toBe(1_000)
        ->and($fee->is_late_fee)->toBeTrue()
        ->and($fee->status)->toBe(InvoiceStatus::Unpaid)
        // Issued, so it has a real document number rather than a draft token.
        ->and($fee->number)->not->toStartWith('DRAFT-')
        // Due immediately: a charge for being late that is itself given thirty
        // days to pay is an argument, not a deterrent.
        ->and($fee->due_on?->toDateString())->toBe(CarbonImmutable::now()->toDateString());

    $overdue->refresh();

    expect([$overdue->subtotal_minor, $overdue->total_minor, $overdue->items()->count()])
        ->toBe($before);

    // The line names the debt, so the amount is traceable rather than mysterious.
    expect($fee->items()->first()?->subject_id)->toBe($overdue->id);
});

it('charges on what is outstanding, not on the total', function (): void {
    BillingSetting::factory()->forOrganization($this->provider->id)->lateFee('10')->create();

    // Half paid is half a debt. Interest on money that already arrived is the
    // error a customer notices once and remembers for years.
    $overdue = overdueInvoiceForTerms(10_000, paid: 6_000);

    expect(app(ChargeLateFee::class)->handle($overdue)?->total_minor)->toBe(400);
});

it('charges nothing when there is nothing to charge', function (): void {
    $overdue = overdueInvoiceForTerms(10_000);

    // No rate stated.
    expect(app(ChargeLateFee::class)->handle($overdue))->toBeNull();

    BillingSetting::factory()->forOrganization($this->provider->id)->lateFee('1')->create();

    // Nothing outstanding.
    $paid = overdueInvoiceForTerms(5_000, paid: 5_000);
    expect(app(ChargeLateFee::class)->handle($paid))->toBeNull();

    // A rate that rounds to nothing on a small balance. An invoice for zero is
    // a document that says nothing and cannot be paid.
    $tiny = overdueInvoiceForTerms(10);
    expect(app(ChargeLateFee::class)->handle($tiny))->toBeNull();

    expect(Invoice::query()->where('is_late_fee', true)->count())->toBe(0);
});

it('charges the fee once, however often the sweep runs', function (): void {
    BillingSetting::factory()->forOrganization($this->provider->id)->lateFee('5')->create();

    DunningStep::factory()->lateFee(7)->create();

    overdueInvoiceForTerms(10_000, dueDaysAgo: 10);

    app(RunDunningSequence::class)->handle();

    expect(Invoice::query()->where('is_late_fee', true)->count())->toBe(1);

    // Run it twice and the second changes nothing: the guard is a row in
    // `invoice_dunning_steps`, not a date calculation (ADR 0031).
    app(RunDunningSequence::class)->handle();
    app(RunDunningSequence::class)->handle();

    expect(Invoice::query()->where('is_late_fee', true)->count())->toBe(1)
        ->and(InvoiceDunningStep::query()->count())->toBe(1);
});

it('never charges a fee on a fee', function (): void {
    BillingSetting::factory()->forOrganization($this->provider->id)->lateFee('5')->create();

    DunningStep::factory()->lateFee(0)->create();

    overdueInvoiceForTerms(10_000, dueDaysAgo: 30);

    // Three nights. Without `is_late_fee` the fee invoice is overdue the day
    // after it is raised and earns a fee of its own, compounding nightly.
    app(RunDunningSequence::class)->handle();
    CarbonImmutable::setTestNow(CarbonImmutable::now()->addDay());
    app(RunDunningSequence::class)->handle();
    CarbonImmutable::setTestNow(CarbonImmutable::now()->addDay());
    app(RunDunningSequence::class)->handle();

    expect(Invoice::query()->where('is_late_fee', true)->count())->toBe(1);

    CarbonImmutable::setTestNow();
});

it('charges a fee to a customer who asked never to be suspended', function (): void {
    BillingSetting::factory()->forOrganization($this->provider->id)->lateFee('5')->create();

    DunningStep::factory()->lateFee(0)->create();

    // "Never suspend us, call us instead" is an arrangement about service. It
    // is not an agreement to owe nothing, and reading it here would let anybody
    // opt out of interest.
    $customer = Customer::factory()->create(['automatic_suspension' => false]);

    overdueInvoiceForTerms(10_000, dueDaysAgo: 5, customer: $customer);

    app(RunDunningSequence::class)->handle();

    expect(Invoice::query()->where('is_late_fee', true)->count())->toBe(1);
});
