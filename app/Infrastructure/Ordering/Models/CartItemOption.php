<?php

declare(strict_types=1);

namespace App\Infrastructure\Ordering\Models;

use App\Infrastructure\Catalog\Models\Option;
use App\Infrastructure\Catalog\Models\OptionGroup;
use App\Infrastructure\Organizations\Concerns\BelongsToOrganization;
use Database\Factories\CartItemOptionFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One answer to one configurable question.
 *
 * References the catalog rather than copying it: a cart is a live thing, and
 * a renamed option should read correctly in it. The copy happens when the
 * order is placed.
 *
 * @property int $quantity
 */
final class CartItemOption extends Model
{
    use BelongsToOrganization;

    /** @use HasFactory<CartItemOptionFactory> */
    use HasFactory;

    use HasUlids;

    protected $table = 'cart_item_options';

    protected $fillable = [
        'organization_id',
        'cart_item_id',
        'option_group_id',
        'option_id',
        'quantity',
    ];

    /**
     * @return BelongsTo<CartItem, $this>
     */
    public function item(): BelongsTo
    {
        return $this->belongsTo(CartItem::class, 'cart_item_id');
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
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
