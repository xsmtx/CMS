<?php

declare(strict_types=1);

namespace App\Infrastructure\Billing\Models;

use App\Domain\Shared\Money;
use App\Infrastructure\Ordering\Models\OrderItem;
use App\Infrastructure\Organizations\Concerns\BelongsToOrganization;
use App\Infrastructure\Shared\Casts\MoneyCast;
use Carbon\CarbonImmutable;
use Database\Factories\InvoiceItemFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line of a bill, as it was worded when the bill was issued.
 *
 * Copied from the order line, which was itself copied from the catalog.
 * Three years later the product may not exist; this line still says what
 * the customer paid for.
 *
 * @property string $description
 * @property int $quantity
 * @property Money $unit_amount
 * @property Money $line_amount
 * @property Money $discount
 * @property Money $tax
 * @property string|null $tax_rate
 * @property CarbonImmutable|null $period_start
 * @property CarbonImmutable|null $period_end
 */
final class InvoiceItem extends Model
{
    use BelongsToOrganization;

    /** @use HasFactory<InvoiceItemFactory> */
    use HasFactory;

    use HasUlids;

    protected $table = 'invoice_items';

    protected $fillable = [
        'organization_id',
        'invoice_id',
        'order_item_id',
        'subject_type',
        'subject_id',
        'description',
        'detail',
        'quantity',
        'currency_code',
        'unit_amount_minor',
        'line_amount_minor',
        'discount_minor',
        'tax_minor',
        'tax_rate',
        'period_start',
        'period_end',
        'position',
    ];

    /**
     * @var array<string, int>
     */
    protected $attributes = [
        'quantity' => 1,
        'unit_amount_minor' => 0,
        'line_amount_minor' => 0,
        'discount_minor' => 0,
        'tax_minor' => 0,
        'position' => 0,
    ];

    /**
     * @return BelongsTo<Invoice, $this>
     */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    /**
     * @return BelongsTo<OrderItem, $this>
     */
    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class, 'order_item_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'unit_amount' => MoneyCast::class.':unit_amount_minor,currency_code',
            'line_amount' => MoneyCast::class.':line_amount_minor,currency_code',
            'discount' => MoneyCast::class.':discount_minor,currency_code',
            'tax' => MoneyCast::class.':tax_minor,currency_code',
            'unit_amount_minor' => 'integer',
            'line_amount_minor' => 'integer',
            'discount_minor' => 'integer',
            'tax_minor' => 'integer',
            'tax_rate' => 'string',
            'period_start' => 'immutable_date',
            'period_end' => 'immutable_date',
            'position' => 'integer',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
