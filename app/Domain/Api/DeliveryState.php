<?php

declare(strict_types=1);

namespace App\Domain\Api;

/**
 * What became of one attempt to post an event.
 *
 * `Pending` exists because the row is written before the HTTP call, the
 * same discipline as the operations centre
 * ([ADR 0032](../../../docs/adr/0032-an-operation-is-visible-before-it-finishes.md)):
 * a delivery that never left the queue is still a row somebody can see.
 */
enum DeliveryState: string
{
    case Pending = 'pending';
    case Delivered = 'delivered';
    case Failed = 'failed';
    case Retrying = 'retrying';

    public function labelKey(): string
    {
        return 'api.deliveries.states.'.$this->value;
    }

    public function isFinished(): bool
    {
        return $this === self::Delivered || $this === self::Failed;
    }
}
