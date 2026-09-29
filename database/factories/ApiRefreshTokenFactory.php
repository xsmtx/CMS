<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Infrastructure\Api\Models\ApiDevice;
use App\Infrastructure\Api\Models\ApiRefreshToken;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ApiRefreshToken>
 */
final class ApiRefreshTokenFactory extends Factory
{
    protected $model = ApiRefreshToken::class;

    public function definition(): array
    {
        return [
            'api_device_id' => fn (): string => ApiDevice::factory()->create()->id,
            'organization_id' => fn (array $attributes): string => ApiDevice::query()
                ->withoutGlobalScope('organization')
                ->whereKey((string) $attributes['api_device_id'])
                ->firstOrFail()
                ->organization_id,
            'token_hash' => fn (): string => hash('sha256', Str::random(64)),
            'expires_at' => CarbonImmutable::now()->addDays(30),
        ];
    }

    public function spent(): self
    {
        return $this->state(fn (): array => ['used_at' => CarbonImmutable::now()->subMinute()]);
    }
}
