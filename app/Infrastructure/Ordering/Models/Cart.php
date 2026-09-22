<?php

declare(strict_types=1);

namespace App\Infrastructure\Ordering\Models;

use App\Infrastructure\Crm\Models\Customer;
use App\Infrastructure\Identity\Models\Contact;
use App\Infrastructure\Organizations\Concerns\BelongsToOrganization;
use App\Infrastructure\Promotions\Models\Promotion;
use Carbon\CarbonImmutable;
use Database\Factories\CartFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * What someone is about to buy.
 *
 * Persisted rather than kept in the session, so signing in halfway through
 * checkout does not empty it and an operator can see what was abandoned.
 *
 * The cart carries no amounts. Product lines are priced from the catalog on
 * every read, which means a cart open in a tab overnight shows this
 * morning's price rather than last night's — and checkout re-prices again
 * before anything is charged.
 *
 * @property string $token
 * @property string $currency_code
 * @property string|null $promotion_code
 * @property CarbonImmutable|null $expires_at
 */
final class Cart extends Model
{
    use BelongsToOrganization;

    /** @use HasFactory<CartFactory> */
    use HasFactory;

    use HasUlids;

    protected $table = 'carts';

    protected $fillable = [
        'organization_id',
        'contact_id',
        'customer_id',
        'token',
        'currency_code',
        'promotion_id',
        'promotion_code',
        'expires_at',
    ];

    /**
     * @return HasMany<CartItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(CartItem::class)->whereNull('parent_id')->orderBy('position');
    }

    /**
     * Every line including addons, for totalling.
     *
     * @return HasMany<CartItem, $this>
     */
    public function allItems(): HasMany
    {
        return $this->hasMany(CartItem::class)->orderBy('position');
    }

    /**
     * @return BelongsTo<Contact, $this>
     */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * @return BelongsTo<Promotion, $this>
     */
    public function promotion(): BelongsTo
    {
        return $this->belongsTo(Promotion::class);
    }

    public function isEmpty(): bool
    {
        return $this->allItems()->count() === 0;
    }

    public function hasExpired(?CarbonImmutable $now = null): bool
    {
        return $this->expires_at !== null && $this->expires_at->isBefore($now ?? CarbonImmutable::now());
    }

    protected static function booted(): void
    {
        self::creating(static function (self $cart): void {
            if ($cart->token === '' || $cart->token === null) {
                $cart->token = (string) Str::ulid().Str::lower(Str::random(8));
            }

            // A cart nobody comes back to is rubbish in the table. Long
            // enough that a customer can leave it overnight and return.
            $cart->expires_at ??= CarbonImmutable::now()->addDays(30);
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'expires_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
