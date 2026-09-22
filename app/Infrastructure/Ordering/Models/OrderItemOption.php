<?php

declare(strict_types=1);

namespace App\Infrastructure\Ordering\Models;

use App\Domain\Shared\Money;
use App\Infrastructure\Catalog\Models\Option;
use App\Infrastructure\Catalog\Models\OptionGroup;
use App\Infrastructure\Organizations\Concerns\BelongsToOrganization;
use App\Infrastructure\Shared\Casts\MoneyCast;
use Database\Factories\OrderItemOptionFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A choice, as it was worded when it was made.
 *
 * The label and the group name are copied. An operator renaming "cPanel" to
 * "cPanel (legacy)" next year must not change what this customer is recorded
 * as having chosen.
 *
 * @property string $group_name
 * @property string $label
 * @property Money $recurring
 * @property Money $setup
 */
final class OrderItemOption extends Model
{
    use BelongsToOrganization;

    /** @use HasFactory<OrderItemOptionFactory> */
    use HasFactory;

    use HasUlids;

    protected $table = 'order_item_options';

    protected $fillable = [
        'organization_id',
        'order_item_id',
        'option_group_id',
        'option_id',
        'group_name',
        'group_key',
        'label',
        'value',
        'quantity',
        'currency_code',
        'recurring_minor',
        'setup_minor',
    ];

    /**
     * @return BelongsTo<OrderItem, $this>
     */
    public function item(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class, 'order_item_id');
    }

    /**
     * @return BelongsTo<OptionGroup, $this>
     */
    public function group(): BelongsTo
    {
        return $this->belongsTo(OptionGroup::class, 'option_group_id');
    }

    /**
     * @return BelongsTo<Option, $this>
     */
    public function option(): BelongsTo
    {
        return $this->belongsTo(Option::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'recurring' => MoneyCast::class.':recurring_minor,currency_code',
            'setup' => MoneyCast::class.':setup_minor,currency_code',
            'recurring_minor' => 'integer',
            'setup_minor' => 'integer',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
