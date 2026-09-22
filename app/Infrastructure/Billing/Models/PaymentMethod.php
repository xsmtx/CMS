<?php

declare(strict_types=1);

namespace App\Infrastructure\Billing\Models;

use App\Infrastructure\Crm\Models\Customer;
use App\Infrastructure\Organizations\Concerns\BelongsToOrganization;
use App\Support\Audit\Contracts\AuditLabel;
use Database\Factories\PaymentMethodFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A card or account a customer has stored — at the gateway.
 *
 * This platform never sees a card number. The row holds the gateway's
 * token, the last four digits and a brand: what a customer needs to
 * recognise which card it is, and nothing a thief can use.
 *
 * @property string $token
 * @property string|null $brand
 * @property string|null $last_four
 * @property bool $is_default
 */
final class PaymentMethod extends Model implements AuditLabel
{
    use BelongsToOrganization;

    /** @use HasFactory<PaymentMethodFactory> */
    use HasFactory;

    use HasUlids;

    protected $table = 'payment_methods';

    protected $fillable = [
        'organization_id',
        'customer_id',
        'gateway',
        'token',
        'brand',
        'last_four',
        'expiry_month',
        'expiry_year',
        'label',
        'is_default',
    ];

    /**
     * The token is a credential at the gateway. It is never rendered and
     * never logged; hiding it means an accidental `toArray()` cannot leak
     * it into a response or a log line.
     */
    protected $hidden = ['token'];

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function displayName(): string
    {
        if ($this->label !== null && $this->label !== '') {
            return $this->label;
        }

        return trim(($this->brand ?? 'Card').' ····'.($this->last_four ?? '????'));
    }

    public function auditLabel(): string
    {
        return $this->displayName();
    }

    protected static function booted(): void
    {
        // Exactly one default per customer.
        $demoteSiblings = static function (self $method): void {
            if (! $method->is_default) {
                return;
            }

            self::query()
                ->withoutGlobalScope('organization')
                ->where('customer_id', $method->customer_id)
                ->whereKeyNot($method->getKey())
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
            'expiry_month' => 'integer',
            'expiry_year' => 'integer',
            'is_default' => 'boolean',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
