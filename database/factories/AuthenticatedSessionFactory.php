<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Identity\Guard;
use App\Infrastructure\Identity\Models\AuthenticatedSession;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<AuthenticatedSession>
 */
final class AuthenticatedSessionFactory extends Factory
{
    protected $model = AuthenticatedSession::class;

    public function definition(): array
    {
        return [
            'session_id' => Str::random(40),
            'guard' => Guard::Staff->value,
            'ip_address' => fake()->ipv4(),
            'user_agent' => 'phpunit',
            'last_active_at' => now(),
        ];
    }
}
