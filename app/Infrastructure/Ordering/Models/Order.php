<?php

declare(strict_types=1);

namespace App\Infrastructure\Ordering\Models;

use App\Domain\Ordering\OrderStatus;
use App\Domain\Risk\RiskDecision;
use App\Domain\Shared\Money;
use App\Infrastructure\Crm\Models\Customer;
use App\Infrastructure\Identity\Models\Contact;
use App\Infrastructure\Organizations\Concerns\BelongsToOrganization;
use App\Infrastructure\Promotions\Models\Promotion;
use App\Infrastructure\Shared\Casts\MoneyCast;
use App\Support\Audit\Contracts\AuditLabel;
use Carbon\CarbonImmutable;
use Database\Factories\OrderFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * What a customer agreed to buy, and on what terms.
 *
 * Every amount here is a total of amounts copied onto the lines. Nothing is
 * recomputed from the catalog: this record has to still say the same thing
 * in three years, after the product has been renamed, repriced and retired.
 *
 * @property string $number
 * @property OrderStatus $status
 * @property string $currency_code
 * @property Money $subtotal
 * @property Money $discount
 * @property Money $setup
 * @property Money $tax
 * @property Money $total
 * @property Money $recurring_total
 * @property RiskDecision|null $risk_decision
 * @property array<int, array<string, mixed>>|null $risk_reasons
 * @property array<int, array<string, mixed>>|null $tax_breakdown
 * @property CarbonImmutable|null $terms_accepted_at
 * @property CarbonImmutable|null $risk_reviewed_at
 * @property CarbonImmutable|null $placed_at
 */
final class Order extends Model implements AuditLabel
{
    use BelongsToOrganization;

    /** @use HasFactory<OrderFactory> */
    use HasFactory;

    use HasUlids;

    protected $table = 'orders';

    protected $fillable = [
        'organization_id',
        'number',
        'customer_id',
        'contact_id',
        'status',
        'currency_code',
        'subtotal_minor',
        'discount_minor',
        'setup_minor',
        'tax_minor',
        'total_minor',
        'recurring_total_minor',
        'promotion_id',
        'promotion_code',
        'tax_breakdown',
        'tax_exemption_reason',
        'risk_decision',
        'risk_score',
        'risk_reasons',
        'risk_reviewed_at',
        'risk_reviewed_by',
        'terms_accepted_at',
        'terms_version',
        'ip_address',
        'user_agent',
        'notes',
        'placed_at',
    ];

    /**
     * Top-level lines. Addons hang off their product line rather than
     * standing beside it.
     *
     * @return HasMany<OrderItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class)->whereNull('parent_id')->orderBy('position');
    }

    /**
     * @return HasMany<OrderItem, $this>
     */
    public function allItems(): HasMany
    {
        return $this->hasMany(OrderItem::class)->orderBy('position');
    }

    /**
     * @return HasMany<OrderStatusChange, $this>
     */
    public function statusHistory(): HasMany
    {
        return $this->hasMany(OrderStatusChange::class)->oldest('occurred_at');
    }

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * @return BelongsTo<Contact, $this>
     */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    /**
     * @return BelongsTo<Promotion, $this>
     */
    public function promotion(): BelongsTo
    {
        return $this->belongsTo(Promotion::class);
    }

    public function auditLabel(): string
    {
        return $this->number;
    }

    public function isOnRiskHold(): bool
    {
        return $this->status === OrderStatus::FraudReview;
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    protected function scopeAwaitingReview(Builder $query): Builder
    {
        return $query->whereIn('status', [
            OrderStatus::FraudReview->value,
            OrderStatus::PaymentReview->value,
        ]);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => OrderStatus::class,
            'risk_decision' => RiskDecision::class,
            'subtotal' => MoneyCast::class.':subtotal_minor,currency_code',
            'discount' => MoneyCast::class.':discount_minor,currency_code',
            'setup' => MoneyCast::class.':setup_minor,currency_code',
            'tax' => MoneyCast::class.':tax_minor,currency_code',
            'total' => MoneyCast::class.':total_minor,currency_code',
            'recurring_total' => MoneyCast::class.':recurring_total_minor,currency_code',
            'subtotal_minor' => 'integer',
            'discount_minor' => 'integer',
            'setup_minor' => 'integer',
            'tax_minor' => 'integer',
            'total_minor' => 'integer',
            'recurring_total_minor' => 'integer',
            'risk_score' => 'integer',
            'risk_reasons' => 'array',
            'tax_breakdown' => 'array',
            'risk_reviewed_at' => 'immutable_datetime',
            'terms_accepted_at' => 'immutable_datetime',
            'placed_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
