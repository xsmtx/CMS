<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Api\WebhookEvent;
use App\Infrastructure\Api\Models\WebhookEndpoint;
use App\Infrastructure\Crm\Models\Customer;
use App\Infrastructure\Organizations\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<WebhookEndpoint>
 */
final class WebhookEndpointFactory extends Factory
{
    protected $model = WebhookEndpoint::class;

    public function definition(): array
    {
        return [
            'organization_id' => fn (): string => Organization::query()
                ->withoutGlobalScope('organization')
                ->whereNull('parent_id')
                ->firstOrFail()
                ->id,
            'customer_id' => null,
            'url' => 'https://example.test/hooks/'.Str::lower(Str::random(8)),
            'description' => 'Billing system',
            'secret' => Str::random(48),
            'events' => null,
            'is_active' => true,
            'consecutive_failures' => 0,
        ];
    }

    public function forCustomer(Customer $customer): self
    {
        return $this->state(fn (): array => [
            'customer_id' => $customer->id,
            'organization_id' => $customer->organization_id,
        ]);
    }

    /**
     * @param  list<WebhookEvent>  $events
     */
    public function subscribedTo(array $events): self
    {
        return $this->state(fn (): array => [
            'events' => array_map(static fn (WebhookEvent $event): string => $event->value, $events),
        ]);
    }

    public function disabled(): self
    {
        return $this->state(fn (): array => ['is_active' => false, 'disabled_at' => now()]);
    }
}
