<?php

declare(strict_types=1);

namespace App\Application\Api;

use App\Domain\Api\DeliveryState;
use App\Domain\Api\WebhookEvent;
use App\Infrastructure\Api\Jobs\DeliverWebhook;
use App\Infrastructure\Api\Models\WebhookDelivery;
use App\Infrastructure\Api\Models\WebhookEndpoint;
use App\Support\Organizations\OrganizationContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * Turns something that happened into rows waiting to be posted.
 *
 * **The payload is built here and stored**, not rebuilt when a delivery is
 * retried. An event is a statement about a moment; re-rendering it from
 * current rows a week later would post a different fact under the same
 * event id, which is exactly what a receiver deduplicating on that id
 * cannot survive.
 *
 * `event_id` is stable across every attempt and every manual redelivery —
 * the same promise this platform asks of its own clients with an
 * idempotency key, made in the other direction.
 *
 * Endpoints are matched inside the boundary escape and narrowed to the
 * event's own organization: a webhook is the customer's, and posting one
 * customer's invoice to another customer's endpoint is the failure this
 * whole layer exists to avoid.
 */
final readonly class DispatchWebhooks
{
    public function __construct(private OrganizationContext $organizations) {}

    /**
     * @param  array<string, mixed>  $payload
     * @return list<WebhookDelivery>
     */
    public function handle(
        WebhookEvent $event,
        string $organizationId,
        array $payload,
        ?string $customerId = null,
    ): array {
        $eventId = (string) Str::ulid();
        $deliveries = [];

        foreach ($this->endpointsFor($organizationId, $customerId) as $endpoint) {
            if (! $endpoint->wants($event)) {
                continue;
            }

            $delivery = $this->organizations->withoutBoundary(
                static fn (): WebhookDelivery => WebhookDelivery::query()->create([
                    'organization_id' => $endpoint->organization_id,
                    'endpoint_id' => $endpoint->id,
                    'event_id' => $eventId,
                    'event' => $event->value,
                    'payload' => [
                        'id' => $eventId,
                        'event' => $event->value,
                        'occurred_at' => CarbonImmutable::now()->toIso8601String(),
                        // Versioned from the first release. A payload that
                        // cannot say which shape it is cannot ever change
                        // shape without breaking every receiver at once.
                        'version' => '1',
                        'data' => $payload,
                    ],
                    'status' => DeliveryState::Pending->value,
                    'created_at' => CarbonImmutable::now(),
                ]),
            );

            dispatch(new DeliverWebhook($delivery->id));

            $deliveries[] = $delivery;
        }

        return $deliveries;
    }

    /**
     * @return list<WebhookEndpoint>
     */
    private function endpointsFor(string $organizationId, ?string $customerId): array
    {
        return $this->organizations->withoutBoundary(
            static fn (): array => array_values(WebhookEndpoint::query()
                ->active()
                ->where('organization_id', $organizationId)
                ->where(function ($query) use ($customerId): void {
                    // An endpoint with no customer is the operator's own
                    // and hears about everything in the organization.
                    $query->whereNull('customer_id');

                    if ($customerId !== null) {
                        $query->orWhere('customer_id', $customerId);
                    }
                })
                ->get()
                ->all()),
        );
    }
}
