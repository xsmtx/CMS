<?php

declare(strict_types=1);

namespace App\Infrastructure\Shared\Models;

use App\Infrastructure\Organizations\Concerns\BelongsToOrganization;
use Database\Factories\NumberSequenceFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * The next human-readable number for a kind of document.
 *
 * A row with a lock rather than `max(number) + 1`: two orders placed in the
 * same second have to get different numbers, and a count cannot promise
 * that. Phase 4 reuses this for invoices and credit notes, which is why it
 * is a general table rather than a column on orders.
 *
 * @property string $key
 * @property string $prefix
 * @property int $next_value
 * @property int $padding
 */
final class NumberSequence extends Model
{
    use BelongsToOrganization;

    /** @use HasFactory<NumberSequenceFactory> */
    use HasFactory;

    use HasUlids;

    protected $table = 'number_sequences';

    protected $fillable = [
        'organization_id',
        'key',
        'prefix',
        'next_value',
        'padding',
    ];

    public function format(int $value): string
    {
        return $this->prefix.str_pad((string) $value, $this->padding, '0', STR_PAD_LEFT);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'next_value' => 'integer',
            'padding' => 'integer',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
