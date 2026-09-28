<?php

declare(strict_types=1);

use App\Application\Access\SyncPermissions;
use App\Application\Automation\TaskRegistry;
use App\Application\Billing\RecordUsage;
use App\Domain\Access\PermissionRegistry;
use App\Domain\Access\SystemRole;
use App\Domain\Automation\AutomationTask;
use App\Domain\Billing\UsageReading;
use App\Domain\Billing\UsageUnit;
use App\Domain\Organizations\OrganizationType;
use App\Domain\Provisioning\ServiceStatus;
use App\Infrastructure\Billing\Models\Invoice;
use App\Infrastructure\Billing\Models\InvoiceItem;
use App\Infrastructure\Billing\Models\UsageMeterRecord;
use App\Infrastructure\Billing\Models\UsageSnapshot;
use App\Infrastructure\Crm\Models\Customer;
use App\Infrastructure\Identity\Models\StaffUser;
use App\Infrastructure\Organizations\Models\Organization;
use App\Infrastructure\Provisioning\Models\Service;
use App\Support\Organizations\OrganizationContext;
use Carbon\CarbonImmutable;
use Database\Seeders\ProviderOrganizationSeeder;
use Database\Seeders\SystemRoleSeeder;

/**
 * Usage metering (§25).
 *
 * **A meter answers quantities and the seller sets the price.** A source that
 * returned money would be a module setting prices, which is the one thing
 * metering must not be able to do.
 *
 * **A snapshot is append-only and quoted by an invoice line.** An invoice is
 * frozen at issue (ADR 0023), so the number on it must come from a row that
 * cannot change — and that row is stamped with the line that quoted it and
 * can never be quoted again. A meter that revises history writes a second
 * snapshot and the correction is a credit note.
 */
beforeEach(function (): void {
    $this->seed(ProviderOrganizationSeeder::class);
    app(SyncPermissions::class)->handle(app(PermissionRegistry::class));
    $this->seed(SystemRoleSeeder::class);

    $this->provider = Organization::query()
        ->where('type', OrganizationType::Provider->value)
        ->firstOrFail();

    app(OrganizationContext::class)->set($this->provider->id);

    $this->admin = StaffUser::factory()->create();
    $this->admin->assignRole(SystemRole::Administrator);
    $this->admin = $this->admin->fresh();

    $this->record = app(RecordUsage::class);
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

/** A customer with one active service, priced for renewal. */
function meteredService(string $company = 'A customer', string $currency = 'EUR'): Service
{
    return app(OrganizationContext::class)->withoutBoundary(static function () use ($company, $currency): Service {
        $customer = Customer::factory()->create(['company_name' => $company]);

        return Service::factory()->create([
            'organization_id' => $customer->organization_id,
            'customer_id' => $customer->id,
            'status' => ServiceStatus::Active->value,
            'name' => 'Hosting',
            'currency_code' => $currency,
            'recurring_minor' => 1000,
            'next_due_on' => CarbonImmutable::now()->addDays(3),
        ]);
    });
}

function meterFor(Service $service, string $serviceKey = 'acct1', int $rate = 3, float $included = 100.0): UsageMeterRecord
{
    return UsageMeterRecord::factory()
        ->priced($rate, $included, $service->currency_code)
        ->create([
            'organization_id' => $service->organization_id,
            'service_id' => $service->id,
            'service_key' => $serviceKey,
        ]);
}

function usageReading(string $serviceKey, float $quantity, string $month = '2026-08', ?UsageUnit $unit = null): UsageReading
{
    $start = CarbonImmutable::parse($month.'-01')->startOfMonth();

    return new UsageReading(
        meterKey: 'bandwidth.out',
        serviceKey: $serviceKey,
        quantity: $quantity,
        unit: $unit ?? UsageUnit::Gigabytes,
        periodStart: $start,
        periodEnd: $start->endOfMonth(),
    );
}

it('records what a source measured, once per period', function (): void {
    $service = meteredService();
    meterFor($service);

    $first = $this->record->handle($this->provider->id, 'test-metering', [usageReading('acct1', 150.5)]);
    $second = $this->record->handle($this->provider->id, 'test-metering', [usageReading('acct1', 150.5)]);

    expect($first['recorded'])->toBe(1)
        ->and($second['recorded'])->toBe(0)
        // Every repeat is a skip, which is what makes running the sweep
        // daily safe (ADR 0031).
        ->and($second['skipped'])->toBe(1)
        ->and(UsageSnapshot::query()->count())->toBe(1)
        ->and(UsageSnapshot::query()->sole()->amount())->toBe(150.5);
});

/**
 * The seller states which services are metered, in what unit and at what
 * rate. Inventing a meter for anything else would be inventing a price.
 */
it('drops a reading for a service nobody metered', function (): void {
    $outcome = $this->record->handle($this->provider->id, 'test-metering', [usageReading('somebody-else', 40)]);

    expect($outcome['unmetered'])->toBe(1)
        ->and(UsageSnapshot::query()->count())->toBe(0);
});

/**
 * The meter declares the billing unit and the source answers in it. Core
 * converts nothing: a conversion here is a rounding error nobody can find
 * between the invoice and the screen.
 */
it('refuses a reading in a unit the meter does not bill in', function (): void {
    $service = meteredService();
    meterFor($service);

    $outcome = $this->record->handle($this->provider->id, 'test-metering', [
        usageReading('acct1', 150, unit: UsageUnit::Terabytes),
    ]);

    expect($outcome['refused'])->toBe(1)
        ->and(UsageSnapshot::query()->count())->toBe(0);
});

/**
 * Rounded once at the line, not per unit: a hundred and fifty gigabytes at a
 * third of a penny is either nothing or a pound if the rate is rounded first.
 */
it('charges above the allowance, rounding once', function (): void {
    $service = meteredService();
    $meter = meterFor($service, rate: 3, included: 100.0);

    // 50.5 over the allowance at 3 minor units: 151.5, rounded to 152.
    expect($meter->charge(150.5)->minorUnits)->toBe(152)
        // Inside the allowance owes nothing, and never a negative.
        ->and($meter->charge(40.0)->minorUnits)->toBe(0);
});

it('puts a usage line on the invoice that renews the service, and stamps the snapshot', function (): void {
    $service = meteredService();
    $meter = meterFor($service, rate: 3, included: 100.0);

    $this->record->handle($this->provider->id, 'test-metering', [usageReading('acct1', 150.5)]);

    app(TaskRegistry::class)->resolve(AutomationTask::Renewals)->handle();

    $invoice = Invoice::query()->sole();
    $usageLine = InvoiceItem::query()->where('subject_type', UsageSnapshot::class)->sole();
    $snapshot = UsageSnapshot::query()->sole();

    expect($usageLine->line_amount_minor)->toBe(152)
        // The line quotes the snapshot: the way back is the row itself.
        ->and($usageLine->subject_id)->toBe($snapshot->id)
        // And the snapshot is stamped, so nothing can quote it again.
        ->and($snapshot->fresh()->invoice_item_id)->toBe($usageLine->id)
        // The renewal and the usage are both on it, and both in the total.
        ->and($invoice->total_minor)->toBe(1000 + 152)
        // The quantity and the allowance are in the words.
        ->and($usageLine->description)->toContain('150.5')
        ->and($usageLine->description)->toContain($meter->meter_key);
});

/**
 * The whole point of stamping. A second renewal must not charge again for a
 * month somebody has already paid for.
 */
it('never quotes one snapshot twice', function (): void {
    $service = meteredService();
    meterFor($service);

    $this->record->handle($this->provider->id, 'test-metering', [usageReading('acct1', 150)]);

    app(TaskRegistry::class)->resolve(AutomationTask::Renewals)->handle();

    // Due again, and nothing new measured.
    $service->fresh()->forceFill([
        'next_due_on' => CarbonImmutable::now()->addDays(3),
        'renewal_invoiced_through' => null,
    ])->save();

    app(TaskRegistry::class)->resolve(AutomationTask::Renewals)->handle();

    expect(InvoiceItem::query()->where('subject_type', UsageSnapshot::class)->count())->toBe(1)
        ->and(Invoice::query()->count())->toBe(2);
});

/**
 * A customer inside their allowance gets a line saying so. A bandwidth line
 * that disappears the month somebody stayed inside it reads as a billing
 * mistake, and it is the month they most want to see the figure.
 */
it('writes a zero line rather than none when the allowance covered it', function (): void {
    $service = meteredService();
    meterFor($service, included: 100.0);

    $this->record->handle($this->provider->id, 'test-metering', [usageReading('acct1', 40)]);

    app(TaskRegistry::class)->resolve(AutomationTask::Renewals)->handle();

    $usageLine = InvoiceItem::query()->where('subject_type', UsageSnapshot::class)->sole();

    expect($usageLine->line_amount_minor)->toBe(0)
        ->and($usageLine->description)->toContain('40');
});

/**
 * Money is never converted in this product, so a meter priced in another
 * currency waits for an invoice in its own.
 */
it('leaves a snapshot in another currency off the invoice', function (): void {
    $service = meteredService(currency: 'EUR');

    UsageMeterRecord::factory()
        ->priced(3, 0.0, 'USD')
        ->create([
            'organization_id' => $service->organization_id,
            'service_id' => $service->id,
            'service_key' => 'acct1',
        ]);

    $this->record->handle($this->provider->id, 'test-metering', [usageReading('acct1', 150)]);

    app(TaskRegistry::class)->resolve(AutomationTask::Renewals)->handle();

    expect(InvoiceItem::query()->where('subject_type', UsageSnapshot::class)->count())->toBe(0)
        ->and(UsageSnapshot::query()->sole()->invoice_item_id)->toBeNull();
});

/**
 * The worst thing this sweep could do: an unreachable source read as a month
 * in which nothing was used, written onto an invoice that cannot be changed.
 */
it('writes nothing when no metering source is configured', function (): void {
    $service = meteredService();
    meterFor($service);

    app(TaskRegistry::class)->resolve(AutomationTask::Usage)->handle();

    expect(UsageSnapshot::query()->count())->toBe(0);
});

/** Every unit an operator or a customer reads is named, in both languages. */
it('names every usage unit in both languages', function (): void {
    foreach (['en', 'tr'] as $locale) {
        app()->setLocale($locale);

        foreach (UsageUnit::cases() as $unit) {
            expect(__($unit->labelKey()))->not->toBe($unit->labelKey());
        }
    }

    app()->setLocale('en');
});
