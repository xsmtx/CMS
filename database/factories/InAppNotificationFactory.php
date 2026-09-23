<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Notifications\NotificationEvent;
use App\Infrastructure\Identity\Models\Contact;
use App\Infrastructure\Notifications\Models\InAppNotification;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;

/**
 * @extends Factory<InAppNotification>
 */
final class InAppNotificationFactory extends Factory
{
    protected $model = InAppNotification::class;

    public function definition(): array
    {
        return [
            'notifiable_type' => Contact::class,
            'notifiable_id' => fn (): string => Contact::factory()->create()->id,
            'event' => NotificationEvent::InvoiceIssued->value,
            'title' => 'Invoice INV-000001',
            'body' => 'Your invoice is ready.',
            'created_at' => now(),
        ];
    }

    public function forNotifiable(Model $notifiable): self
    {
        return $this->state(fn (): array => [
            'notifiable_type' => $notifiable::class,
            'notifiable_id' => (string) $notifiable->getKey(),
        ]);
    }
}
