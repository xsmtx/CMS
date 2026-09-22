<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Infrastructure\Audit\Models\AuditLog;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<AuditLog>
 */
final class AuditLogFactory extends Factory
{
    protected $model = AuditLog::class;

    public function definition(): array
    {
        return [
            'action' => 'platform.example.performed',
            'actor_type' => null,
            'actor_id' => null,
            'actor_label' => 'system',
            'target_type' => null,
            'target_id' => null,
            'target_label' => null,
            'changes' => null,
            'metadata' => null,
            'reason' => null,
            'ip_address' => fake()->ipv4(),
            'user_agent' => 'phpunit',
            'correlation_id' => (string) Str::ulid(),
            'occurred_at' => now(),
        ];
    }
}
