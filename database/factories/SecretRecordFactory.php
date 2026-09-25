<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Infrastructure\Organizations\Models\Organization;
use App\Infrastructure\Secrets\Models\SecretRecord;
use App\Support\Organizations\OrganizationContext;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SecretRecord>
 */
final class SecretRecordFactory extends Factory
{
    protected $model = SecretRecord::class;

    public function definition(): array
    {
        return [
            'organization_id' => $this->owningOrganization(...),
            'reference' => 'monitoring/token/'.fake()->unique()->lexify('????????'),
            'value' => fake()->sha256(),
            'last_rotated_at' => now(),
        ];
    }

    public function reference(string $reference): self
    {
        return $this->state(fn (): array => ['reference' => $reference]);
    }

    public function forOrganization(Organization|string $organization): self
    {
        return $this->state(fn (): array => [
            'organization_id' => $organization instanceof Organization ? $organization->id : $organization,
        ]);
    }

    private function owningOrganization(): string
    {
        $boundary = app(OrganizationContext::class)->id();

        if (is_string($boundary) && $boundary !== '') {
            return $boundary;
        }

        return Organization::query()
            ->withoutGlobalScope('organization')
            ->whereNull('parent_id')
            ->firstOrFail()
            ->id;
    }
}
