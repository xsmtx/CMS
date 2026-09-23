<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Api\DeliveryState;
use App\Domain\Api\WebhookEvent;
use App\Infrastructure\Api\Models\WebhookDelivery;
use App\Infrastructure\Api\Models\WebhookEndpoint;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<WebhookDelivery>
 */
final class WebhookDeliveryFactory extends Factory
{
    protected $model = WebhookDelivery::class;

    public function definition(): array
    {
        return [
            'endpoint_id' => fn (): string => WebhookEndpoint::factory()->create()->id,
            'organization_id' => fn (array $attributes): string => WebhookEndpoint::query()
                ->withoutGlobalScope('organization')
                ->whereKey((string) $attributes['endpoint_id'])
                ->firstOrFail()
                ->organization_id,
            'event_id' => fn (): string => (string) Str::ulid(),
            'event' => WebhookEvent::InvoicePaid->value,
            'payload' => ['id' => 'INV-000001'],
            'status' => DeliveryState::Pending->value,
            'attempt' => 0,
            'created_at' => now(),
        ];
    }

    public function delivered(): self
    {
        return $this->state(fn (): array => [
            'status' => DeliveryState::Delivered->value,
            'attempt' => 1,
            'response_status' => 200,
            'delivered_at' => now(),
        ]);
    }

    public function dueForRetry(): self
    {
        return $this->state(fn (): array => [
            'status' => DeliveryState::Retrying->value,
            'attempt' => 1,
            'response_status' => 500,
            'next_attempt_at' => now()->subMinute(),
        ]);
    }
}
