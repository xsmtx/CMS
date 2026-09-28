<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Dcim\PartKind;
use App\Infrastructure\Dcim\Models\HardwarePart;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<HardwarePart>
 */
final class HardwarePartFactory extends Factory
{
    protected $model = HardwarePart::class;

    public function definition(): array
    {
        return [
            'kind' => PartKind::Disk,
            'model' => 'MZ7LH3T8',
            'serial' => 'S'.fake()->unique()->numerify('##########'),
            'vendor' => 'Samsung',
            'purchased_on' => CarbonImmutable::now()->subYears(2),
            'warranty_until' => CarbonImmutable::now()->addYear(),
        ];
    }

    public function of(PartKind $kind): self
    {
        return $this->state(fn (): array => ['kind' => $kind]);
    }

    public function outOfWarranty(): self
    {
        return $this->state(fn (): array => [
            'warranty_until' => CarbonImmutable::now()->subMonth(),
        ]);
    }

    /** No warranty recorded, which is a gap rather than an expiry. */
    public function withNoWarranty(): self
    {
        return $this->state(fn (): array => ['warranty_until' => null]);
    }
}
