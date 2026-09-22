<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Identity\AccountStatus;
use App\Domain\Organizations\OrganizationType;
use App\Infrastructure\Identity\Models\StaffUser;
use App\Infrastructure\Organizations\Models\Organization;
use App\Support\Organizations\OrganizationContext;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<StaffUser>
 */
final class StaffUserFactory extends Factory
{
    protected $model = StaffUser::class;

    /**
     * Hashing once per process keeps large factory runs out of bcrypt.
     */
    protected static ?string $password = null;

    public function definition(): array
    {
        return [
            'organization_id' => $this->owningOrganizationId(...),
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => self::$password ??= Hash::make('password'),
            'status' => AccountStatus::Active->value,
            'remember_token' => Str::random(10),
        ];
    }

    public function suspended(): static
    {
        return $this->state(fn (): array => ['status' => AccountStatus::Suspended->value]);
    }

    public function closed(): static
    {
        return $this->state(fn (): array => ['status' => AccountStatus::Closed->value]);
    }

    public function withTwoFactor(string $secret = 'JBSWY3DPEHPK3PXP'): static
    {
        return $this->state(fn (): array => [
            'two_factor_secret' => $secret,
            'two_factor_recovery_codes' => [Hash::make('aaaaa-bbbbb')],
            'two_factor_confirmed_at' => now(),
        ]);
    }

    public function forOrganization(Organization|string $organization): static
    {
        return $this->state(fn (): array => [
            'organization_id' => $organization instanceof Organization ? $organization->id : $organization,
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

        return is_string($existing)
            ? $existing
            : Organization::factory()->provider()->create()->id;
    }
}
