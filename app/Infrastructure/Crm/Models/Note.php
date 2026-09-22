<?php

declare(strict_types=1);

namespace App\Infrastructure\Crm\Models;

use App\Infrastructure\Organizations\Concerns\BelongsToOrganization;
use Carbon\CarbonImmutable;
use Database\Factories\NoteFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * A written note attached to a record.
 *
 * Internal by default. A note only reaches a customer when somebody
 * deliberately marks it visible, because the alternative failure mode is a
 * staff aside about a customer appearing in that customer's portal.
 *
 * @property string $id
 * @property string|null $author_label
 * @property string $body
 * @property bool $is_customer_visible
 * @property bool $is_pinned
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
final class Note extends Model
{
    use BelongsToOrganization;

    /** @use HasFactory<NoteFactory> */
    use HasFactory;

    use HasUlids;

    protected $table = 'notes';

    protected $fillable = [
        'organization_id',
        'author_type',
        'author_id',
        'author_label',
        'body',
        'is_customer_visible',
        'is_pinned',
    ];

    /**
     * @return MorphTo<Model, $this>
     */
    public function notable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function author(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    protected function scopeCustomerVisible(Builder $query): Builder
    {
        return $query->where('is_customer_visible', true);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_customer_visible' => 'boolean',
            'is_pinned' => 'boolean',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
