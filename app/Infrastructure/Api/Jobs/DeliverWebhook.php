<?php

declare(strict_types=1);

namespace App\Infrastructure\Api\Jobs;

use App\Application\Api\DeliverWebhookNow;
use App\Infrastructure\Api\Models\WebhookDelivery;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Posts one webhook delivery.
 *
 * `tries(1)` on purpose. The retry lives in the delivery row and its
 * `next_attempt_at`, not in the queue, for the reason the operations centre
 * exists: a delayed job is a promise held by Redis, and a Redis that was
 * flushed has silently dropped every one of them. A row with a date in the
 * past survives that, and the sweep picks it up.
 *
 * The id travels rather than the model: by the time a retry runs, the row
 * has moved on.
 */
final class DeliverWebhook implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(public readonly string $deliveryId)
    {
        $this->onQueue('webhooks');
    }

    public function tries(): int
    {
        return 1;
    }

    public function handle(DeliverWebhookNow $deliver): void
    {
        // Outside the boundary: a worker acts for the platform and has no
        // request to take one from.
        $delivery = WebhookDelivery::query()
            ->withoutGlobalScope('organization')
            ->find($this->deliveryId);

        if (! $delivery instanceof WebhookDelivery) {
            return;
        }

        $deliver->handle($delivery);
    }
}
