<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Infrastructure\Network\Models\IpAddressRecord;
use App\Infrastructure\Network\Models\IpAssignment;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;

/**
 * @extends Factory<IpAssignment>
 */
final class IpAssignmentFactory extends Factory
{
    protected $model = IpAssignment::class;

    public function definition(): array
    {
        return [
            'holder_label' => fake()->domainName(),
            'assigned_at' => now(),
            'released_at' => null,
        ];
    }

    public function of(IpAddressRecord $address, Model $holder): self
    {
        return $this->state(fn (): array => [
            'organization_id' => $address->organization_id,
            'ip_address_id' => $address->id,
            'holder_type' => $holder->getMorphClass(),
            'holder_id' => $holder->getKey(),
        ]);
    }
}
