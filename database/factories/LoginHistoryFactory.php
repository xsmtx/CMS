<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Identity\Guard;
use App\Infrastructure\Identity\Models\LoginHistory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<LoginHistory>
 */
final class LoginHistoryFactory extends Factory
{
    protected $model = LoginHistory::class;

    public function definition(): array
    {
        return [
            'guard' => Guard::Staff->value,
            'email_attempted' => fake()->safeEmail(),
            'successful' => true,
            'ip_address' => fake()->ipv4(),
            'user_agent' => 'phpunit',
            'correlation_id' => (string) Str::ulid(),
            'occurred_at' => now(),
        ];
    }
}
