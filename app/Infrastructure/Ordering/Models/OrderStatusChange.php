<?php

declare(strict_types=1);

namespace App\Infrastructure\Ordering\Models;

use App\Domain\Ordering\OrderStatus;
use App\Infrastructure\Organizations\Concerns\BelongsToOrganization;
use Carbon\CarbonImmutable;
use Database\Factories\OrderStatusChangeFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/**
 * One step in an order's life.
 *
 * Append-only: this is the order's account of itself, and an order that
 * could rewrite its own history would be no evidence of anything.
 *
 * @property OrderStatus|null $from_status
 * @property OrderStatus $to_status
 * @property CarbonImmutable $occurred_at
 */
final class OrderStatusChange extends Model
{
    use BelongsToOrganization;

    /** @use HasFactory<OrderStatusChangeFactory> */
    use HasFactory;

    use HasUlids;

    public $timestamps = false;

    protected $table = 'order_status_history';

    protected $guarded = [];

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    protected static function booted(): void
    {
        self::updating(static function (): never {
            throw new RuntimeException('Order status history is append-only and cannot be updated.');
        });

        self::deleting(static function (): never {
            throw new RuntimeException('Order status history is append-only and cannot be deleted.');
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'from_status' => OrderStatus::class,
            'to_status' => OrderStatus::class,
            'occurred_at' => 'immutable_datetime',
        ];
    }
}
