<?php

declare(strict_types=1);

use App\Application\Shared\AllocateNumber;
use App\Domain\Organizations\OrganizationType;
use App\Infrastructure\Crm\Models\Customer;
use App\Infrastructure\Organizations\Models\Organization;
use App\Infrastructure\Shared\Models\NumberSequence;
use Database\Seeders\ProviderOrganizationSeeder;
use Illuminate\Support\Str;

beforeEach(function (): void {
    $this->seed(ProviderOrganizationSeeder::class);
    $this->provider = Organization::query()->withoutGlobalScope('organization')->whereNull('parent_id')->sole();
});

/**
 * A customer of the given seller — an organization of its own, which is the
 * whole reason numbering has to look upwards.
 */
function customerUnder(Organization $seller): Customer
{
    $organization = Organization::query()->create([
        'parent_id' => $seller->id,
        'type' => OrganizationType::Customer->value,
        'name' => 'Customer',
        'slug' => 'customer-'.Str::lower(Str::random(6)),
        'is_active' => true,
    ]);

    return Customer::factory()->forOrganization($organization)->create();
}

it('numbers documents for the seller, not for each customer', function (): void {
    // A customer is an organization of its own. Numbering against it would
    // give every customer their own ORD-000001.
    $first = customerUnder($this->provider);
    $second = customerUnder($this->provider);

    $numbers = app(AllocateNumber::class);

    expect($numbers->handle($first->organization_id, 'order', 'ORD-'))->toBe('ORD-000001')
        ->and($numbers->handle($second->organization_id, 'order', 'ORD-'))->toBe('ORD-000002')
        ->and($numbers->handle($first->organization_id, 'order', 'ORD-'))->toBe('ORD-000003');
});

it('keeps one sequence per seller and kind', function (): void {
    $customer = customerUnder($this->provider);
    $numbers = app(AllocateNumber::class);

    $numbers->handle($customer->organization_id, 'order', 'ORD-');
    $numbers->handle($customer->organization_id, 'invoice', 'INV-');

    $sequences = NumberSequence::query()->withoutGlobalScope('organization')->get();

    expect($sequences)->toHaveCount(2)
        ->and($sequences->pluck('organization_id')->unique()->all())->toBe([$this->provider->id]);
});

it('numbers a resellers customers within that reseller', function (): void {
    $reseller = Organization::query()->create([
        'parent_id' => $this->provider->id,
        'type' => OrganizationType::Reseller->value,
        'name' => 'Reseller',
        'slug' => 'reseller',
        'is_active' => true,
    ]);

    $direct = customerUnder($this->provider);
    $theirs = customerUnder($reseller);

    $numbers = app(AllocateNumber::class);

    // Two sequences, because a reseller bills in its own name. Phase 11
    // will need to give them different prefixes; the numbers themselves are
    // unique in the database, which is what stops a silent collision.
    expect($numbers->handle($direct->organization_id, 'order', 'ORD-'))->toBe('ORD-000001')
        ->and($numbers->handle($theirs->organization_id, 'order', 'ORD-'))->toBe('ORD-000001')
        ->and(NumberSequence::query()->withoutGlobalScope('organization')->count())->toBe(2);
});

it('numbers in the sellers name even when nobody is inside a boundary', function (): void {
    $customer = customerUnder($this->provider);

    expect(app(AllocateNumber::class)->handle($customer->organization_id, 'invoice', 'INV-'))
        ->toBe('INV-000001');
});
