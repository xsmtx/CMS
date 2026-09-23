<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Notifications\DeliveryStatus;
use App\Domain\Notifications\NotificationChannel;
use App\Domain\Notifications\NotificationEvent;
use App\Infrastructure\Notifications\Models\NotificationDelivery;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<NotificationDelivery>
 */
final class NotificationDeliveryFactory extends Factory
{
    protected $model = NotificationDelivery::class;

    public function definition(): array
    {
        return [
            'organization_id' => null,
            'event' => NotificationEvent::InvoiceIssued->value,
            'channel' => NotificationChannel::Mail->value,
            'status' => DeliveryStatus::Sent->value,
            'recipient_name' => fake()->name(),
            'recipient_address' => fake()->safeEmail(),
            'locale' => 'en',
            'created_at' => now(),
            'delivered_at' => now(),
        ];
    }
}
