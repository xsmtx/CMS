<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Crm\CustomerStatus;
use App\Domain\Organizations\OrganizationType;
use App\Infrastructure\Crm\Models\Customer;
use App\Infrastructure\Organizations\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Customer>
 */
final class CustomerFactory extends Factory
{
    protected $model = Customer::class;

    public function definition(): array
    {
        return [
            // A customer profile describes an organization, so the factory
            // creates one rather than borrowing the ambient boundary. A
            // customer sharing an organization with its reseller would not
            // be a separate customer at all.
            'organization_id' => fn (): string => $this->customerOrganization()->id,
            'company_name' => fake()->company(),
            'legal_name' => fake()->company().' Ltd',
            'tax_id' => strtoupper(fake()->bothify('??#########')),
            'tax_id_type' => 'vat',
            'status' => CustomerStatus::Active->value,
            'currency_code' => 'EUR',
            'marketing_opt_in' => false,
        ];
    }

    public function pending(): static
    {
        return $this->state(fn (): array => ['status' => CustomerStatus::Pending->value]);
    }

    public function suspended(): static
    {
        return $this->state(fn (): array => ['status' => CustomerStatus::Suspended->value]);
    }

    public function forOrganization(Organization|string $organization): static
    {
        return $this->state(fn (): array => [
            'organization_id' => $organization instanceof Organization ? $organization->id : $organization,
        ]);
    }

    private function customerOrganization(): Organization
    {
        $parent = Organization::query()
            ->withoutGlobalScope('organization')
            ->where('type', OrganizationType::Provider->value)
            ->first() ?? Organization::factory()->provider()->create();

        return Organization::factory()->customerOf($parent)->create();
    }
}
