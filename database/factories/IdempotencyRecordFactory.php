<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Infrastructure\Api\Models\IdempotencyRecord;
use App\Infrastructure\Organizations\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<IdempotencyRecord>
 */
final class IdempotencyRecordFactory extends Factory
{
    protected $model = IdempotencyRecord::class;

    public function definition(): array
    {
        return [
            'organization_id' => fn (): string => Organization::query()
                ->withoutGlobalScope('organization')
                ->whereNull('parent_id')
                ->firstOrFail()
                ->id,
            'token_id' => null,
            'key' => (string) Str::uuid(),
            'fingerprint' => hash('sha256', 'POST api/v1/tickets {}'),
            'created_at' => now(),
        ];
    }

    public function completed(int $status = 201, string $response = '{"data":{}}'): self
    {
        return $this->state(fn (): array => [
            'status' => $status,
            'response' => $response,
            'completed_at' => now(),
        ]);
    }
}
