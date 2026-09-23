<?php

declare(strict_types=1);

namespace App\Infrastructure\Crm\Models;

use App\Domain\Crm\AddressType;
use App\Infrastructure\Organizations\Concerns\BelongsToOrganization;
use Database\Factories\AddressFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * A postal address belonging to a customer or a contact.
 *
 * Invoices snapshot the address at issue time rather than joining to this
 * row, so correcting a typo here never rewrites a document that has already
 * been sent.
 *
 * @property string $id
 * @property AddressType $type
 * @property string $line_one
 * @property string|null $line_two
 * @property string $city
 * @property string|null $region
 * @property string|null $postal_code
 * @property string $country_code
 * @property bool $is_default
 */
final class Address extends Model
{
    use BelongsToOrganization;

    /** @use HasFactory<AddressFactory> */
    use HasFactory;

    use HasUlids;

    protected $table = 'addresses';

    protected $fillable = [
        'organization_id',
        'type',
        'label',
        'line_one',
        'line_two',
        'city',
        'region',
        'postal_code',
        'country_code',
        'is_default',
    ];

    /**
     * @return MorphTo<Model, $this>
     */
    public function addressable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Single-line rendering for lists and audit labels.
     */
    public function toSingleLine(): string
    {
        return implode(', ', array_filter([
            $this->line_one,
            $this->line_two,
            $this->postal_code,
            $this->city,
            $this->region,
            $this->country_code,
        ]));
    }

    /**
     * @return array<string, mixed>
     */
    public function toSnapshot(): array
    {
        return [
            'line_one' => $this->line_one,
            'line_two' => $this->line_two,
            'city' => $this->city,
            'region' => $this->region,
            'postal_code' => $this->postal_code,
            'country_code' => $this->country_code,
        ];
    }

    protected static function booted(): void
    {
        // Exactly one default per owner and type. Enforced here rather than
        // in each caller, because the second place that forgets is the one
        // that puts two addresses on an invoice.
        $demoteSiblings = static function (self $address): void {
            if (! $address->is_default) {
                return;
            }

            self::query()
                ->where('addressable_type', $address->addressable_type)
                ->where('addressable_id', $address->addressable_id)
                ->where('type', $address->type->value)
                ->whereKeyNot($address->getKey())
                ->update(['is_default' => false]);
        };

        self::created($demoteSiblings);
        self::updated($demoteSiblings);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => AddressType::class,
            'is_default' => 'boolean',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
