<?php

declare(strict_types=1);

namespace App\Infrastructure\Provisioning\Models;

use App\Domain\Catalog\BillingCycle;
use App\Domain\Provisioning\ServiceReference;
use App\Domain\Provisioning\ServiceStatus;
use App\Domain\Shared\Money;
use App\Infrastructure\Catalog\Models\Product;
use App\Infrastructure\Crm\Models\Customer;
use App\Infrastructure\Ordering\Models\Order;
use App\Infrastructure\Ordering\Models\OrderItem;
use App\Infrastructure\Organizations\Concerns\BelongsToOrganization;
use App\Infrastructure\Shared\Casts\MoneyCast;
use App\Support\Audit\Contracts\AuditLabel;
use Carbon\CarbonImmutable;
use Database\Factories\ServiceFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Something a customer is running.
 *
 * Everything commercial on this row is a **copy** taken when the service
 * was created: the name, the cycle, the price, the package and the chosen
 * options. A product repriced next March does not change what an existing
 * service costs, and a renamed option does not rewrite the disk quota
 * somebody is running on.
 *
 * `external_id` is what the provider calls the account. It is written the
 * moment an adapter returns it, before anything else, because an id that
 * was not stored is an account nobody can find again.
 *
 * @property string $id
 * @property ServiceStatus $status
 * @property string $name
 * @property string|null $package
 * @property BillingCycle|null $billing_cycle
 * @property string $currency_code
 * @property Money $recurring
 * @property Money $setup
 * @property string|null $domain
 * @property string|null $external_id
 * @property string|null $username
 * @property string|null $password
 * @property array<string, mixed>|null $configuration
 * @property CarbonImmutable|null $starts_on
 * @property CarbonImmutable|null $next_due_on
 * @property CarbonImmutable|null $ends_on
 * @property CarbonImmutable|null $provisioned_at
 * @property CarbonImmutable|null $suspended_at
 * @property CarbonImmutable|null $terminated_at
 * @property CarbonImmutable|null $synced_at
 */
final class Service extends Model implements AuditLabel
{
    use BelongsToOrganization;

    /** @use HasFactory<ServiceFactory> */
    use HasFactory;

    use HasUlids;

    protected $table = 'services';

    protected $fillable = [
        'organization_id',
        'customer_id',
        'order_id',
        'order_item_id',
        'product_id',
        'server_id',
        'module',
        'status',
        'name',
        'package',
        'billing_cycle',
        'currency_code',
        'recurring_minor',
        'setup_minor',
        'domain',
        'hostname',
        'external_id',
        'username',
        'password',
        'configuration',
        'starts_on',
        'next_due_on',
        'ends_on',
        'suspension_reason',
        'failure_reason',
        'provisioned_at',
        'suspended_at',
        'terminated_at',
        'synced_at',
    ];

    /**
     * The password the provider issued. Encrypted at rest and hidden, so
     * an accidental `toArray()` cannot put somebody's control panel
     * credential into a response.
     */
    protected $hidden = ['password'];

    /**
     * Money columns have database defaults, which fill the row but leave
     * the model in memory without the attribute — and the money cast reads
     * that absence as null.
     *
     * @var array<string, int|string>
     */
    protected $attributes = [
        'recurring_minor' => 0,
        'setup_minor' => 0,
        'status' => 'pending',
    ];

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
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * @return BelongsTo<Server, $this>
     */
    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    /**
     * @return HasMany<ServiceOption, $this>
     */
    public function options(): HasMany
    {
        return $this->hasMany(ServiceOption::class)->orderBy('position');
    }

    /**
     * @return HasMany<ServiceEvent, $this>
     */
    public function events(): HasMany
    {
        return $this->hasMany(ServiceEvent::class)->latest('occurred_at');
    }

    /**
     * What an adapter is allowed to know about this service.
     */
    public function reference(): ServiceReference
    {
        $server = $this->server;

        return new ServiceReference(
            id: $this->id,
            server: $server instanceof Server ? $server->connection() : null,
            externalId: $this->external_id,
            username: $this->username,
            domain: $this->domain,
            package: $this->package,
        );
    }

    public function auditLabel(): string
    {
        return $this->name.($this->domain === null ? '' : ' ('.$this->domain.')');
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    protected function scopeNeedingAttention(Builder $query): Builder
    {
        return $query->whereIn('status', [
            ServiceStatus::Failed->value,
            ServiceStatus::Pending->value,
        ]);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => ServiceStatus::class,
            'billing_cycle' => BillingCycle::class,
            'recurring' => MoneyCast::class.':recurring_minor',
            'setup' => MoneyCast::class.':setup_minor',
            'password' => 'encrypted',
            'configuration' => 'array',
            'starts_on' => 'immutable_date',
            'next_due_on' => 'immutable_date',
            'ends_on' => 'immutable_date',
            'provisioned_at' => 'immutable_datetime',
            'suspended_at' => 'immutable_datetime',
            'terminated_at' => 'immutable_datetime',
            'synced_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
