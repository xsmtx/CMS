<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Infrastructure\Security\Models\Certificate;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Certificate>
 */
final class CertificateFactory extends Factory
{
    protected $model = Certificate::class;

    public function definition(): array
    {
        $name = $this->faker->unique()->domainName();

        return [
            'source' => 'test-probe',
            'fingerprint' => $this->faker->unique()->sha256(),
            'common_name' => $name,
            'subject_alternative_names' => [$name, 'www.'.$name],
            'issuer' => "Let's Encrypt R3",
            'serial' => $this->faker->unique()->numerify('##################'),
            'not_before' => CarbonImmutable::now()->subDays(30),
            'not_after' => CarbonImmutable::now()->addDays(60),
            'chain_ok' => true,
            'discovered_at' => CarbonImmutable::now(),
        ];
    }

    /** Expiring in a given number of days, which is what a rule asks about. */
    public function expiringIn(int $days): self
    {
        return $this->state(fn (): array => [
            'not_after' => CarbonImmutable::now()->addDays($days),
        ]);
    }

    public function expired(): self
    {
        return $this->state(fn (): array => [
            'not_before' => CarbonImmutable::now()->subYear(),
            'not_after' => CarbonImmutable::now()->subDays(3),
        ]);
    }

    /**
     * @param  list<string>  $names
     */
    public function covering(array $names): self
    {
        return $this->state(fn (): array => [
            'common_name' => $names[0] ?? 'example.com',
            'subject_alternative_names' => $names,
        ]);
    }

    public function retired(): self
    {
        return $this->state(fn (): array => ['retired_at' => CarbonImmutable::now()]);
    }
}
