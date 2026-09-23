<?php

declare(strict_types=1);

namespace App\Http\Controllers\Client;

use App\Domain\Api\WebhookEvent;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\WebhookEndpointRequest;
use App\Infrastructure\Api\Jobs\DeliverWebhook;
use App\Infrastructure\Api\Models\WebhookDelivery;
use App\Infrastructure\Api\Models\WebhookEndpoint;
use App\Support\Audit\Facades\Audit;
use App\Support\Errors\ForbiddenException;
use App\Support\Identity\CurrentActor;
use App\Support\Identity\CurrentCustomer;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/**
 * A customer's own webhook endpoints, from the portal.
 *
 * The same rows the API manages, because there is one answer to "where do
 * my events go" and it must not depend on which surface asked. The screen
 * exists because most customers will never write a line of code against
 * the API and still want their billing system told when an invoice is
 * paid.
 *
 * The signing secret is shown exactly once, on the redirect that created
 * the endpoint. It is a credential: anybody holding it can forge an event
 * from us.
 */
final class WebhookController extends Controller
{
    public function __construct(
        private readonly CurrentCustomer $customer,
        private readonly CurrentActor $actor,
    ) {}

    public function index(): Response
    {
        $this->authorizeWebhooks();

        $endpoints = $this->customer
            ->owned(WebhookEndpoint::query())
            ->latest('created_at')
            ->get();

        return Inertia::render('Client/Developer/Webhooks', [
            'endpoints' => $endpoints
                ->map(static fn (WebhookEndpoint $endpoint): array => [
                    'id' => $endpoint->id,
                    'url' => $endpoint->url,
                    'description' => $endpoint->description,
                    'events' => $endpoint->events ?? [],
                    'isActive' => $endpoint->is_active && $endpoint->disabled_at === null,
                    'failures' => $endpoint->consecutive_failures,
                    'lastDeliveredAt' => $endpoint->last_delivered_at?->toIso8601String(),
                    'disabledAt' => $endpoint->disabled_at?->toIso8601String(),
                ])
                ->values()
                ->all(),
            'deliveries' => $this->recentDeliveries(array_values(
                $endpoints->map(static fn (WebhookEndpoint $endpoint): string => $endpoint->id)->all(),
            )),
            'events' => WebhookEvent::values(),
            'issued' => session('issuedSecret'),
        ]);
    }

    public function store(WebhookEndpointRequest $request): RedirectResponse
    {
        $this->authorizeWebhooks();

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

        Audit::action('portal.webhook.created')
            ->by($this->customer->contact())
            ->on($endpoint)
            ->forOrganization($endpoint->organization_id)
            ->write();

        return back()
            ->with('status', __('api.webhooks.created'))
            // The only time this value is readable. Flashed, so a refresh
            // does not show it again.
            ->with('issuedSecret', $secret);
    }

    public function destroy(string $endpoint): RedirectResponse
    {
        $this->authorizeWebhooks();

        /** @var WebhookEndpoint $record */
        $record = $this->customer->find(WebhookEndpoint::query()->whereKey($endpoint));

        Audit::action('portal.webhook.deleted')
            ->by($this->customer->contact())
            ->on($record)
            ->forOrganization($record->organization_id)
            ->write();

        $record->delete();

        return back()->with('status', __('api.webhooks.deleted'));
    }

    public function redeliver(string $delivery): RedirectResponse
    {
        $this->authorizeWebhooks();

        $record = WebhookDelivery::query()->whereKey($delivery)->first();

        if (! $record instanceof WebhookDelivery) {
            abort(404);
        }

        // The endpoint has to be this customer's, or a delivery id would be
        // a way to make the platform post somebody else's payload.
        $this->customer->find(WebhookEndpoint::query()->whereKey($record->endpoint_id));

        $record->forceFill(['next_attempt_at' => CarbonImmutable::now()])->save();

        dispatch(new DeliverWebhook($record->id));

        return back()->with('status', __('api.webhooks.redelivered'));
    }

    /**
     * @param  list<string>  $endpointIds
     * @return list<array<string, mixed>>
     */
    private function recentDeliveries(array $endpointIds): array
    {
        if ($endpointIds === []) {
            return [];
        }

        return array_values(WebhookDelivery::query()
            ->whereIn('endpoint_id', $endpointIds)
            ->latest('created_at')
            ->limit(25)
            ->get()
            ->map(static fn (WebhookDelivery $delivery): array => [
                'id' => $delivery->id,
                'endpointId' => $delivery->endpoint_id,
                'event' => $delivery->event->value,
                'status' => $delivery->status->value,
                'statusLabel' => (string) __($delivery->status->labelKey()),
                'attempt' => $delivery->attempt,
                'responseStatus' => $delivery->response_status,
                'error' => $delivery->error,
                'createdAt' => $delivery->created_at?->toIso8601String(),
            ])
            ->values()
            ->all());
    }

    private function authorizeWebhooks(): void
    {
        if (! $this->actor->can('portal.tokens.manage')) {
            throw new ForbiddenException(__('api.errors.forbidden'));
        }
    }
}
