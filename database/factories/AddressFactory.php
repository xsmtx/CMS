<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Crm\AddressType;
use App\Infrastructure\Crm\Models\Address;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Address>
 */
final class AddressFactory extends Factory
{
    protected $model = Address::class;

    public function definition(): array
    {
        return [
            'type' => AddressType::Billing->value,
            'line_one' => fake()->streetAddress(),
            'city' => fake()->city(),
            'postal_code' => fake()->postcode(),
            'country_code' => 'NL',
            'is_default' => true,
        ];
    }

    public function ofType(AddressType $type): static
    {
        return $this->state(fn (): array => ['type' => $type->value]);
    }
}
