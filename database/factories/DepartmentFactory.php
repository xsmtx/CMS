<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Infrastructure\Organizations\Models\Organization;
use App\Infrastructure\Support\Models\Department;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Department>
 */
final class DepartmentFactory extends Factory
{
    protected $model = Department::class;

    public function definition(): array
    {
        $name = Str::title(fake()->unique()->word().' Support');

        return [
            'organization_id' => fn (): string => Organization::query()
                ->withoutGlobalScope('organization')
                ->whereNull('parent_id')
                ->firstOrFail()
                ->id,
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(4)),
            'email' => null,
            'first_response_minutes' => 240,
            'resolution_minutes' => 1440,
            'is_public' => true,
            'is_active' => true,
            'position' => 0,
        ];
    }

    /**
     * A queue nobody measures. A real configuration, not an error.
     */
    public function withoutSla(): self
    {
        return $this->state(fn (): array => [
            'first_response_minutes' => null,
            'resolution_minutes' => null,
        ]);
    }

    public function sla(int $firstResponseMinutes, ?int $resolutionMinutes = null): self
    {
        return $this->state(fn (): array => [
            'first_response_minutes' => $firstResponseMinutes,
            'resolution_minutes' => $resolutionMinutes ?? $firstResponseMinutes * 6,
        ]);
    }

    public function forOrganization(Organization $organization): self
    {
        return $this->state(fn (): array => ['organization_id' => $organization->id]);
    }
}
