<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Infrastructure\Identity\Models\Impersonation;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Impersonation>
 */
final class ImpersonationFactory extends Factory
{
    protected $model = Impersonation::class;

    public function definition(): array
    {
        return [
            'reason' => 'Investigating a billing discrepancy reported by the customer.',
            'ip_address' => fake()->ipv4(),
            'correlation_id' => (string) Str::ulid(),
            'started_at' => now(),
            'ended_at' => null,
        ];
    }
}
