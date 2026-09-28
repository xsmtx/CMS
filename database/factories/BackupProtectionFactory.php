<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Infrastructure\Backup\BackupOutcome;
use App\Infrastructure\Backup\Models\BackupProtection;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BackupProtection>
 */
final class BackupProtectionFactory extends Factory
{
    protected $model = BackupProtection::class;

    public function definition(): array
    {
        $name = 'host-'.fake()->numberBetween(1, 400).'.example.test';

        return [
            'source' => 'test-backup',
            'resource_key' => 'job-'.fake()->unique()->numberBetween(1, 100000),
            'resource_name' => $name,
            'resource_type' => 'account',
            'repository' => 'nightly',
            'last_outcome' => BackupOutcome::Succeeded,
            'last_run_at' => CarbonImmutable::now()->subHours(6),
            'last_good_at' => CarbonImmutable::now()->subHours(6),
            'restore_points' => 14,
            'size_bytes' => 4_000_000_000,
            'first_seen_at' => CarbonImmutable::now()->subMonth(),
            'last_seen_at' => CarbonImmutable::now(),
        ];
    }

    public function named(string $name): self
    {
        return $this->state(fn (): array => ['resource_name' => $name]);
    }

    /** A last good copy this many days old, whatever the last run said. */
    public function lastGoodDaysAgo(int $days): self
    {
        return $this->state(fn (): array => [
            'last_good_at' => CarbonImmutable::now()->subDays($days),
        ]);
    }

    /** Added to a job and never yet run: no good copy, and not a failure. */
    public function neverRun(): self
    {
        return $this->state(fn (): array => [
            'last_outcome' => BackupOutcome::Unknown,
            'last_run_at' => null,
            'last_good_at' => null,
            'restore_points' => null,
        ]);
    }

    public function failing(): self
    {
        return $this->state(fn (): array => [
            'last_outcome' => BackupOutcome::Failed,
            'last_run_at' => CarbonImmutable::now()->subHours(6),
            'last_good_at' => CarbonImmutable::now()->subDays(9),
        ]);
    }

    public function retired(): self
    {
        return $this->state(fn (): array => ['retired_at' => CarbonImmutable::now()]);
    }
}
