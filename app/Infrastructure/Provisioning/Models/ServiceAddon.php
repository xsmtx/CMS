<?php

declare(strict_types=1);

namespace App\Infrastructure\Provisioning\Models;

use App\Domain\Catalog\BillingCycle;
use App\Domain\Provisioning\AddonStatus;
use App\Domain\Shared\Money;
use App\Infrastructure\Catalog\Models\Addon;
use App\Infrastructure\Crm\Models\Customer;
use App\Infrastructure\Ordering\Models\Order;
use App\Infrastructure\Ordering\Models\OrderItem;
use App\Infrastructure\Organizations\Concerns\BelongsToOrganization;
use App\Infrastructure\Shared\Casts\MoneyCast;
use App\Support\Audit\Contracts\AuditLabel;
use Carbon\CarbonImmutable;
use Database\Factories\ServiceAddonFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An addon a customer is paying for, on a service they are running.
 *
 * A copy of what was bought, like a service and like an order line
 * (ADR 0021): the name, the cycle and every amount are written here when
 * the order is paid, and nothing reads a price back through the catalog.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $customer_id
 * @property string $service_id
 * @property string|null $order_id
 * @property string|null $order_item_id
 * @property string|null $addon_id
 * @property AddonStatus $status
 * @property string $name
 * @property BillingCycle|null $billing_cycle
 * @property string $currency_code
 * @property Money $recurring
 * @property Money $setup
 * @property int $quantity
 * @property CarbonImmutable|null $starts_on
 * @property CarbonImmutable|null $next_due_on
 * @property CarbonImmutable|null $renewal_invoiced_through
 * @property CarbonImmutable|null $ends_on
 * @property CarbonImmutable|null $suspended_at
 * @property CarbonImmutable|null $terminated_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
final class ServiceAddon extends Model implements AuditLabel
{
    use BelongsToOrganization;

    /** @use HasFactory<ServiceAddonFactory> */
    use HasFactory;

    use HasUlids;

    protected $table = 'service_addons';

    protected $fillable = [
        'organization_id',
        'customer_id',
        'service_id',
        'order_id',
        'order_item_id',
        'addon_id',
        'status',
        'name',
        'billing_cycle',
        'currency_code',
        'recurring_minor',
        'setup_minor',
        'quantity',
        'starts_on',
        'next_due_on',
        'renewal_invoiced_through',
        'ends_on',
        'suspended_at',
        'terminated_at',
    ];

    /**
     * The money columns and the status have database defaults, which fill
     * the row but leave the model in memory without the attribute — and
     * the money cast reads that absence as null.
     *
     * @var array<string, int|string>
     */
    protected $attributes = [
        'recurring_minor' => 0,
        'setup_minor' => 0,
        'quantity' => 1,
        'status' => 'pending',
    ];

    /**
     * @return BelongsTo<Service, $this>
     */
    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * @return BelongsTo<OrderItem, $this>
     */
    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class);
    }

    /**
     * @return BelongsTo<Addon, $this>
     */
    public function addon(): BelongsTo
    {
        return $this->belongsTo(Addon::class);
    }

    public function auditLabel(): string
    {
        return $this->name;
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    protected function scopeBillable(Builder $query): Builder
    {
        return $query->whereIn('status', array_map(
            static fn (AddonStatus $status): string => $status->value,
            AddonStatus::billable(),
        ));
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => AddonStatus::class,
            'billing_cycle' => BillingCycle::class,
            'recurring' => MoneyCast::class.':recurring_minor',
            'setup' => MoneyCast::class.':setup_minor',
            'quantity' => 'integer',
            'starts_on' => 'immutable_date',
            'next_due_on' => 'immutable_date',
            'renewal_invoiced_through' => 'immutable_date',
            'ends_on' => 'immutable_date',
            'suspended_at' => 'immutable_datetime',
            'terminated_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
