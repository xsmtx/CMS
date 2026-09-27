<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Infrastructure\Reliability\Models\MaintenanceWindow;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MaintenanceWindow>
 */
final class MaintenanceWindowFactory extends Factory
{
    protected $model = MaintenanceWindow::class;

    public function definition(): array
    {
        return [
            'title' => 'Switch firmware on the core pair',
            'starts_at' => CarbonImmutable::now()->addDay(),
            'ends_at' => CarbonImmutable::now()->addDay()->addHours(2),
            'is_public' => false,
            'node_keys' => [],
        ];
    }

    /** Running right now. */
    public function running(): self
    {
        return $this->state(fn (): array => [
            'starts_at' => CarbonImmutable::now()->subMinutes(10),
            'ends_at' => CarbonImmutable::now()->addHour(),
        ]);
    }

    public function over(): self
    {
        return $this->state(fn (): array => [
            'starts_at' => CarbonImmutable::now()->subHours(3),
            'ends_at' => CarbonImmutable::now()->subHour(),
        ]);
    }

    public function published(): self
    {
        return $this->state(fn (): array => ['is_public' => true]);
    }

    /**
     * @param  list<string>  $keys
     */
    public function covering(array $keys): self
    {
        return $this->state(fn (): array => ['node_keys' => $keys]);
    }
}
