<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Network\AddressState;
use App\Domain\Network\IpAddress;
use App\Infrastructure\Network\Models\IpAddressRecord;
use App\Infrastructure\Network\Models\IpPrefixRecord;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<IpAddressRecord>
 */
final class IpAddressRecordFactory extends Factory
{
    protected $model = IpAddressRecord::class;

    public function definition(): array
    {
        $address = IpAddress::parse('192.0.2.10');

        return [
            'address' => $address->text(),
            'address_bytes' => $address->bytes,
            'family' => $address->family->value,
            'state' => AddressState::Available->value,
        ];
    }

    public function inPrefix(IpPrefixRecord $prefix): self
    {
        return $this->state(fn (): array => [
            'organization_id' => $prefix->organization_id,
            'ip_prefix_id' => $prefix->id,
        ]);
    }

    public function of(string $address): self
    {
        $parsed = IpAddress::parse($address);

        return $this->state(fn (): array => [
            'address' => $parsed->text(),
            'address_bytes' => $parsed->bytes,
            'family' => $parsed->family->value,
        ]);
    }

    public function state_(AddressState $state): self
    {
        return $this->state(fn (): array => ['state' => $state->value]);
    }
}
