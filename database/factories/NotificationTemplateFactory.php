<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Notifications\NotificationEvent;
use App\Infrastructure\Notifications\Models\NotificationTemplate;
use App\Infrastructure\Organizations\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<NotificationTemplate>
 */
final class NotificationTemplateFactory extends Factory
{
    protected $model = NotificationTemplate::class;

    public function definition(): array
    {
        return [
            'organization_id' => fn (): string => Organization::query()
                ->withoutGlobalScope('organization')
                ->whereNull('parent_id')
                ->firstOrFail()
                ->id,
            'event' => NotificationEvent::InvoiceIssued->value,
            'locale' => 'en',
            'subject' => 'Invoice :invoice_number',
            'body' => 'Your invoice :invoice_number for :total is ready.',
            'action_label' => 'View invoice',
            'is_customised' => true,
            'is_active' => true,
        ];
    }

    public function forEvent(NotificationEvent $event, string $locale = 'en'): self
    {
        return $this->state(fn (): array => ['event' => $event->value, 'locale' => $locale]);
    }
}
