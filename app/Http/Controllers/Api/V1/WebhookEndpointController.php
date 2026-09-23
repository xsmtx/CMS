<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Api\DeliveryState;
use App\Domain\Api\WebhookEvent;
use App\Http\Api\ApiResource;
use App\Http\Requests\Api\WebhookEndpointRequest;
use App\Infrastructure\Api\Jobs\DeliverWebhook;
use App\Infrastructure\Api\Models\WebhookDelivery;
use App\Infrastructure\Api\Models\WebhookEndpoint;
use App\Support\Audit\Facades\Audit;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Where a customer wants this platform's events posted.
 *
 * **The secret is returned once**, in the response that created the
 * endpoint, and never again. It is a credential: anybody holding it can
 * forge an event from us, and a platform that will re-read it on request
 * has turned every read of the endpoint list into a way to steal it.
 *
 * Redelivery re-posts the **stored** payload rather than rebuilding it. An
 * event is a statement about a moment; rebuilding it from current rows a
 * week later would post a different fact under the same event id, which is
 * precisely what a receiver deduplicating on that id cannot survive.
 */
final class WebhookEndpointController extends ApiController
{
    public function index(): JsonResponse
    {
        $endpoints = $this->customer
            ->owned(WebhookEndpoint::query())
            ->latest('created_at')
            ->get();

        return new JsonResponse(ApiResource::item([
            'endpoints' => $endpoints
                ->map($this->row(...))
                ->values()
                ->all(),
            'events' => WebhookEvent::values(),
        ]));
    }

    public function store(WebhookEndpointRequest $request): JsonResponse
    {
        $secret = Str::random(48);

        /** @var list<string> $events */
        $events = $request->validated('events') ?? [];

        $endpoint = WebhookEndpoint::query()->create([
            'organization_id' => $this->customer->model()->organization_id,
            'customer_id' => $this->customer->model()->id,
            'url' => $request->string('url')->toString(),
            'description' => $request->input('description'),
            'secret' => $secret,
            'events' => $events,
            'is_active' => true,
        ]);

        Audit::action('api.webhook.created')
            ->by($this->customer->contact())
            ->on($endpoint)
            ->forOrganization($endpoint->organization_id)
            ->write();

        return new JsonResponse(
            ApiResource::item([
                ...$this->row($endpoint),
                // The only time this is ever readable. Store it now.
                'secret' => $secret,
            ]),
            JsonResponse::HTTP_CREATED,
        );
    }

    public function destroy(string $endpoint): JsonResponse
    {
        /** @var WebhookEndpoint $record */
        $record = $this->customer->find(WebhookEndpoint::query()->whereKey($endpoint));

        Audit::action('api.webhook.deleted')
            ->by($this->customer->contact())
            ->on($record)
            ->forOrganization($record->organization_id)
            ->write();

        $record->delete();

        return new JsonResponse(null, JsonResponse::HTTP_NO_CONTENT);
    }

    public function deliveries(Request $request, string $endpoint): JsonResponse
    {
        /** @var WebhookEndpoint $record */
        $record = $this->customer->find(WebhookEndpoint::query()->whereKey($endpoint));

        $deliveries = WebhookDelivery::query()
            ->where('endpoint_id', $record->id)
            ->latest('created_at')
            ->paginate(min(max((int) $request->query('per_page', '25'), 1), 100));

        return new JsonResponse(ApiResource::page(
            $deliveries,
            array_values(array_map(
                static fn (WebhookDelivery $delivery): array => [
                    'id' => $delivery->id,
                    'event_id' => $delivery->event_id,
                    'event' => $delivery->event->value,
                    'status' => $delivery->status->value,
                    'attempt' => $delivery->attempt,
                    'response_status' => $delivery->response_status,
                    'error' => $delivery->error,
                    'duration_ms' => $delivery->duration_ms,
                    'delivered_at' => $delivery->delivered_at?->toIso8601String(),
                    'created_at' => $delivery->created_at?->toIso8601String(),
                ],
                $deliveries->items(),
            )),
        ));
    }

    public function redeliver(string $delivery): JsonResponse
    {
        $record = WebhookDelivery::query()->whereKey($delivery)->first();

        if (! $record instanceof WebhookDelivery) {
            throw new NotFoundHttpException;
        }

        // The endpoint has to be this customer's. Without this check a
        // delivery id would be a way to make the platform post somebody
        // else's payload on demand.
        $this->customer->find(WebhookEndpoint::query()->whereKey($record->endpoint_id));

        $record->forceFill([
            'status' => DeliveryState::Pending->value,
            'next_attempt_at' => CarbonImmutable::now(),
        ])->save();

        dispatch(new DeliverWebhook($record->id));

        return new JsonResponse(
            ApiResource::item([
                'id' => $record->id,
                'event_id' => $record->event_id,
                'status' => 'queued',
            ]),
            JsonResponse::HTTP_ACCEPTED,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function row(WebhookEndpoint $endpoint): array
    {
        return [
            'id' => $endpoint->id,
            'url' => $endpoint->url,
            'description' => $endpoint->description,
            'events' => $endpoint->events ?? [],
            'is_active' => $endpoint->is_active && $endpoint->disabled_at === null,
            'consecutive_failures' => $endpoint->consecutive_failures,
            'last_delivered_at' => $endpoint->last_delivered_at?->toIso8601String(),
            'disabled_at' => $endpoint->disabled_at?->toIso8601String(),
            'created_at' => $endpoint->created_at?->toIso8601String(),
        ];
    }
}
