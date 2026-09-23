<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Infrastructure\Organizations\Models\Organization;
use App\Infrastructure\Support\Models\CannedResponse;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<CannedResponse>
 */
final class CannedResponseFactory extends Factory
{
    protected $model = CannedResponse::class;

    public function definition(): array
    {
        return [
            'organization_id' => fn (): string => Organization::query()
                ->withoutGlobalScope('organization')
                ->whereNull('parent_id')
                ->firstOrFail()
                ->id,
            'name' => Str::title(fake()->word().' '.fake()->word()),
            'body' => fake()->paragraph(),
            'used_count' => 0,
        ];
    }
}
