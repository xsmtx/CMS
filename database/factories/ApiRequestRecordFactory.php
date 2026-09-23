<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Infrastructure\Api\Models\ApiRequestRecord;
use App\Infrastructure\Organizations\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ApiRequestRecord>
 */
final class ApiRequestRecordFactory extends Factory
{
    protected $model = ApiRequestRecord::class;

    public function definition(): array
    {
        return [
            'organization_id' => fn (): string => Organization::query()
                ->withoutGlobalScope('organization')
                ->whereNull('parent_id')
                ->firstOrFail()
                ->id,
            'method' => 'GET',
            'path' => 'api/v1/services',
            'route' => 'api.v1.services.index',
            'status' => 200,
            'duration_ms' => 12,
            'ip' => '198.51.100.7',
            'created_at' => now(),
        ];
    }

    public function refused(int $status = 403, string $code = 'forbidden'): self
    {
        return $this->state(fn (): array => ['status' => $status, 'error_code' => $code]);
    }
}
