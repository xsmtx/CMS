<?php

declare(strict_types=1);

use App\Infrastructure\Organizations\Models\Organization;
use App\Infrastructure\Shared\Models\CurrencyRecord;
use App\Infrastructure\Shared\Models\ExchangeRateSnapshot;
use App\Support\Organizations\OrganizationContext;

beforeEach(function (): void {
    $this->provider = Organization::factory()->provider()->create(['name' => 'InfraCMS']);
    $this->reseller = Organization::factory()->reseller($this->provider)->create(['name' => 'Reseller A']);
    $this->context = app(OrganizationContext::class);
});

it('normalises the code and takes the exponent from ISO 4217', function (): void {
    $record = CurrencyRecord::factory()->create(['code' => 'jpy', 'name' => 'Japanese Yen', 'exponent' => 2]);

    // An operator typing 2 for the yen would silently divide every price by
    // a hundred, so the ISO definition wins over the form field.
    expect($record->code)->toBe('JPY')
        ->and($record->exponent)->toBe(0)
        ->and($record->currency()->exponent)->toBe(0);
});

it('keeps three decimals for a currency that has them', function (): void {
    $record = CurrencyRecord::factory()->create(['code' => 'KWD', 'name' => 'Kuwaiti Dinar']);

    expect($record->exponent)->toBe(3);
});

it('forces the base currency rate to one', function (): void {
    $record = CurrencyRecord::factory()->base()->create(['code' => 'EUR', 'rate' => '1.35000000']);

    expect($record->rate)->toBe('1.00000000');
});

it('allows only one base currency per organization', function (): void {
    $euro = CurrencyRecord::factory()->forOrganization($this->provider)->base()->code('EUR', 'Euro')->create();
    $dollar = CurrencyRecord::factory()->forOrganization($this->provider)->base()->code('USD', 'US Dollar')->create();

    expect($euro->fresh()?->is_base)->toBeFalse()
        ->and($dollar->fresh()?->is_base)->toBeTrue();
});

it('lets each organization keep its own base currency', function (): void {
    $providerBase = CurrencyRecord::factory()->forOrganization($this->provider)->base()->code('EUR', 'Euro')->create();
    $resellerBase = CurrencyRecord::factory()->forOrganization($this->reseller)->base()->code('TRY', 'Turkish Lira')->create();

    expect($providerBase->fresh()?->is_base)->toBeTrue()
        ->and($resellerBase->fresh()?->is_base)->toBeTrue();
});

it('keeps the rate a string so it never passes through a float', function (): void {
    $record = CurrencyRecord::factory()->code('TRY', 'Turkish Lira', '42.12345678')->create();

    expect($record->fresh()?->rate)->toBeString()
        ->and($record->fresh()?->rate)->toBe('42.12345678');
});

it('scopes currencies to their organization', function (): void {
    CurrencyRecord::factory()->forOrganization($this->provider)->code('EUR', 'Euro')->create();
    CurrencyRecord::factory()->forOrganization($this->reseller)->code('TRY', 'Turkish Lira')->create();

    $this->context->runAs($this->reseller->id, function (): void {
        expect(CurrencyRecord::query()->pluck('code')->all())->toBe(['TRY']);
    });
});

it('refuses to update an exchange rate snapshot', function (): void {
    $snapshot = ExchangeRateSnapshot::factory()->create();

    $snapshot->update(['rate' => '2.00000000']);
})->throws(RuntimeException::class);

it('refuses to delete an exchange rate snapshot', function (): void {
    ExchangeRateSnapshot::factory()->create()->delete();
})->throws(RuntimeException::class);

it('keeps the rate history in capture order', function (): void {
    $record = CurrencyRecord::factory()->code('TRY', 'Turkish Lira', '40.00000000')->create();

    ExchangeRateSnapshot::factory()->create([
        'currency_id' => $record->id,
        'organization_id' => $record->organization_id,
        'code' => 'TRY',
        'rate' => '38.00000000',
        'captured_at' => now()->subDays(2),
    ]);

    ExchangeRateSnapshot::factory()->create([
        'currency_id' => $record->id,
        'organization_id' => $record->organization_id,
        'code' => 'TRY',
        'rate' => '40.00000000',
        'captured_at' => now(),
    ]);

    expect($record->snapshots()->pluck('rate')->all())->toBe(['40.00000000', '38.00000000']);
});
