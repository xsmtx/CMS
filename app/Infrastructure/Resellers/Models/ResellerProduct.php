<?php

declare(strict_types=1);

namespace App\Infrastructure\Resellers\Models;

use App\Infrastructure\Catalog\Models\Product;
use App\Infrastructure\Organizations\Concerns\BelongsToOrganization;
use Database\Factories\ResellerProductFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A product one reseller may sell, and the markup they put on it.
 *
 * `organization_id` is the **reseller**. The row is owned by them like every
 * other owned row here, which is what makes "a reseller cannot see another
 * reseller's margins" true by the same mechanism as everything else rather
 * than by a clause somebody has to remember to write.
 *
 * `margin_percent` is nullable, and **null is not zero**. Null means "sell
 * it at the provider's price"; `0.0000` means somebody typed zero. The
 * screen shows the difference, because an operator who cleared a field did
 * not necessarily mean to agree to sell at cost.
 *
 * @property string|null $margin_percent
 * @property bool $is_enabled
 */
final class ResellerProduct extends Model
{
    use BelongsToOrganization;

    /** @use HasFactory<ResellerProductFactory> */
    use HasFactory;

    use HasUlids;

    protected $table = 'reseller_products';

    protected $fillable = [
        'organization_id',
        'product_id',
        'margin_percent',
        'is_enabled',
    ];

    /**
     * The database default, repeated: a default fills the row but leaves the
     * model in memory without the attribute, and the boolean cast reads that
     * absence as null. It bit the booleans in Phase 7.
     *
     * @var array<string, bool>
     */
    protected $attributes = ['is_enabled' => true];

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_enabled' => 'boolean',
            // Read as a string, never a float: a margin is applied with
            // integer arithmetic to integer minor units, and a float in the
            // middle is how 19.99 becomes 19.989999999999998.
            'margin_percent' => 'string',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
