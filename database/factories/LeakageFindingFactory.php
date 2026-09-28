<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Intelligence\LeakageKind;
use App\Infrastructure\Intelligence\Models\LeakageFinding;
use App\Infrastructure\Provisioning\Models\Service;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LeakageFinding>
 */
final class LeakageFindingFactory extends Factory
{
    protected $model = LeakageFinding::class;

    public function definition(): array
    {
        return [
            'kind' => LeakageKind::ServiceNotBilled,
            'subject_type' => Service::class,
            'subject_id' => (string) str()->ulid(),
            'subject_label' => 'shop.example',
            'currency_code' => 'EUR',
            'amount_minor' => 1990,
            'first_seen_at' => CarbonImmutable::now()->subDays(2),
            'last_seen_at' => CarbonImmutable::now(),
            'cleared_token' => '',
        ];
    }

    public function of(LeakageKind $kind): self
    {
        return $this->state(fn (): array => ['kind' => $kind]);
    }

    public function worth(int $minor, string $currency = 'EUR'): self
    {
        return $this->state(fn (): array => [
            'amount_minor' => $minor,
            'currency_code' => $currency,
        ]);
    }
}
