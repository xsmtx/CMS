<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Security\FindingSeverity;
use App\Domain\Security\ZoneCheck;
use App\Infrastructure\Security\Models\ZoneFinding;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ZoneFinding>
 */
final class ZoneFindingFactory extends Factory
{
    protected $model = ZoneFinding::class;

    public function definition(): array
    {
        return [
            'check' => ZoneCheck::SpfMissing,
            'severity' => FindingSeverity::Warning,
            'source' => 'test-dns',
            'first_seen_at' => CarbonImmutable::now()->subDays(2),
            'last_seen_at' => CarbonImmutable::now(),
            'cleared_token' => '',
        ];
    }

    public function of(ZoneCheck $check): self
    {
        return $this->state(fn (): array => ['check' => $check]);
    }

    public function cleared(): self
    {
        return $this->state(fn (array $attributes): array => [
            'cleared_at' => CarbonImmutable::now(),
            'cleared_token' => (string) ($attributes['id'] ?? 'cleared'),
        ]);
    }
}
