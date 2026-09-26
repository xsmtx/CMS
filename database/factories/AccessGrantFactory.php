<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Network\GrantableCapability;
use App\Infrastructure\Network\Models\AccessGrant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AccessGrant>
 */
final class AccessGrantFactory extends Factory
{
    protected $model = AccessGrant::class;

    public function definition(): array
    {
        return [
            'capability' => GrantableCapability::Connect,
            'reason' => 'Ticket 2291: the customer cannot reach their mailbox.',
            'expires_at' => CarbonImmutable::now()->addHours(2),
        ];
    }

    /** Already past its window, for the sweep. */
    public function lapsed(): self
    {
        return $this->state(fn (): array => [
            'expires_at' => CarbonImmutable::now()->subMinutes(5),
        ]);
    }
}
