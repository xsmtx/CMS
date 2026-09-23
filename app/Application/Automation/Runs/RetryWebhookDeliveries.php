<?php

declare(strict_types=1);

namespace App\Application\Automation\Runs;

use App\Domain\Automation\Contracts\AutomationRun;
use App\Domain\Automation\ItemOutcome;
use App\Domain\Automation\RunItem;
use App\Domain\Automation\RunSummary;
use App\Infrastructure\Api\Jobs\DeliverWebhook;
use App\Infrastructure\Api\Models\WebhookDelivery;
use App\Support\Organizations\OrganizationContext;
use Throwable;

/**
 * Re-queues webhook deliveries whose next attempt is due.
 *
 * The same shape as the operations retry sweep and for the same reason: the
 * schedule lives in a row rather than in a delayed job, so a flushed Redis
 * loses nothing. An operator's endpoint that was down for an hour gets its
 * events when it comes back.
 */
final readonly class RetryWebhookDeliveries implements AutomationRun
{
    public function __construct(private OrganizationContext $organizations) {}

    public function handle(): RunSummary
    {
        $summary = new RunSummary;

        foreach ($this->due() as $delivery) {
            $summary = $summary->examining();

            try {
                dispatch(new DeliverWebhook($delivery->id));

                $summary = $summary->changing(new RunItem(
                    ItemOutcome::Changed,
                    WebhookDelivery::class,
                    $delivery->id,
                    $delivery->event->value,
                    'attempt '.($delivery->attempt + 1),
                ));
            } catch (Throwable $exception) {
                $summary = $summary->failing(new RunItem(
                    ItemOutcome::Failed,
                    WebhookDelivery::class,
                    $delivery->id,
                    $delivery->event->value,
                    $exception->getMessage(),
                ));
            }
        }

        return $summary;
    }

    /**
     * @return list<WebhookDelivery>
     */
    private function due(): array
    {
        return $this->organizations->withoutBoundary(
            static fn (): array => array_values(WebhookDelivery::query()
                ->dueForRetry()
                ->orderBy('next_attempt_at')
                ->limit(200)
                ->get()
                ->all()),
        );
    }
}
