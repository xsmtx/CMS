<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Network\IpAddress;
use App\Infrastructure\Security\Models\ReputationListing;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ReputationListing>
 */
final class ReputationListingFactory extends Factory
{
    protected $model = ReputationListing::class;

    public function definition(): array
    {
        // 192.0.2.0/24 is TEST-NET-1 (RFC 5737). A fixture that used a real
        // address would be a fixture naming somebody else's machine.
        $address = IpAddress::parse('192.0.2.'.fake()->numberBetween(1, 254));

        return [
            'address' => $address->text(),
            'address_bytes' => $address->bytes,
            'list' => 'zen.spamhaus.org',
            'reason' => null,
            'delist_url' => null,
            'source' => 'test-reputation',
            'first_seen_at' => CarbonImmutable::now()->subDays(2),
            'last_seen_at' => CarbonImmutable::now(),
            'cleared_token' => '',
        ];
    }

    public function at(string $address): self
    {
        $parsed = IpAddress::parse($address);

        return $this->state(fn (): array => [
            'address' => $parsed->text(),
            'address_bytes' => $parsed->bytes,
        ]);
    }

    public function on(string $list): self
    {
        return $this->state(fn (): array => ['list' => $list]);
    }

    public function cleared(): self
    {
        return $this->state(fn (array $attributes): array => [
            'cleared_at' => CarbonImmutable::now(),
            'cleared_token' => (string) ($attributes['id'] ?? 'cleared'),
        ]);
    }
}
