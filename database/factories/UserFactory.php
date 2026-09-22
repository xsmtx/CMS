<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Organizations\OrganizationType;
use App\Infrastructure\Identity\Models\User;
use App\Infrastructure\Organizations\Models\Organization;
use App\Support\Organizations\OrganizationContext;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
final class UserFactory extends Factory
{
    protected $model = User::class;

    /**
     * Hashing once per process keeps large factory runs from spending
     * seconds inside bcrypt.
     */
    protected static ?string $password = null;

    public function definition(): array
    {
        return [
            // Every account is owned. The ambient boundary wins when there is
            // one, so a factory call inside `runAs()` lands where the test
            // expects; otherwise the provider organization is used, creating
            // it if this is the first account in the test.
            'organization_id' => $this->owningOrganizationId(...),
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => self::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
        ];
    }

    public function unverified(): static
    {
        return $this->state(fn (array $attributes): array => [
            'email_verified_at' => null,
        ]);
    }

    public function forOrganization(Organization|string $organization): static
    {
        return $this->state(fn (array $attributes): array => [
            'organization_id' => $organization instanceof Organization
                ? $organization->id
                : $organization,
        ]);
    }

    private function owningOrganizationId(): string
    {
        $current = app(OrganizationContext::class)->id();

        if ($current !== null) {
            return $current;
        }

        $existing = Organization::query()
            ->withoutGlobalScope('organization')
            ->where('type', OrganizationType::Provider->value)
            ->value('id');

        if (is_string($existing)) {
            return $existing;
        }

        return Organization::factory()->provider()->create()->id;
    }
}
