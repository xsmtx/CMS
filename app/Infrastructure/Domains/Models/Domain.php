<?php

declare(strict_types=1);

namespace App\Infrastructure\Domains\Models;

use App\Domain\Domains\DomainName;
use App\Domain\Domains\DomainReference;
use App\Domain\Domains\DomainStatus;
use App\Domain\Shared\Money;
use App\Infrastructure\Crm\Models\Customer;
use App\Infrastructure\Ordering\Models\Order;
use App\Infrastructure\Ordering\Models\OrderItem;
use App\Infrastructure\Organizations\Concerns\BelongsToOrganization;
use App\Infrastructure\Shared\Casts\MoneyCast;
use App\Support\Audit\Contracts\AuditLabel;
use Carbon\CarbonImmutable;
use Database\Factories\DomainFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A name a customer holds.
 *
 * The label and the extension are both copied onto the row, and so is the
 * assembled name: a TLD renamed or removed from the catalog must not change
 * what an existing domain is called.
 *
 * Two things are deliberately **not** here. The registrant, because the
 * registry holds the authoritative copy and a second one would drift. And
 * the EPP transfer code, because it is the credential that moves a domain
 * away and a stored copy is one somebody has to protect forever.
 *
 * @property string $id
 * @property DomainStatus $status
 * @property string $label
 * @property string $extension
 * @property string $name
 * @property string|null $registrar
 * @property int $years
 * @property string $currency_code
 * @property Money $renewal
 * @property string|null $external_id
 * @property CarbonImmutable|null $registered_on
 * @property CarbonImmutable|null $expires_on
 * @property bool $auto_renew
 * @property bool $registrar_lock
 * @property bool $whois_privacy
 * @property list<string>|null $nameservers
 * @property string|null $failure_reason
 * @property CarbonImmutable|null $synced_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
final class Domain extends Model implements AuditLabel
{
    use BelongsToOrganization;

    /** @use HasFactory<DomainFactory> */
    use HasFactory;

    use HasUlids;

    protected $table = 'domains';

    protected $fillable = [
        'organization_id',
        'customer_id',
        'order_id',
        'order_item_id',
        'tld_id',
        'registrar',
        'status',
        'label',
        'extension',
        'name',
        'years',
        'currency_code',
        'renewal_minor',
        'external_id',
        'registered_on',
        'expires_on',
        'auto_renew',
        'registrar_lock',
        'whois_privacy',
        'nameservers',
        'failure_reason',
        'synced_at',
    ];

    /**
     * A database default fills the row but leaves the model in memory
     * without the attribute — and a cast reads that absence as null. It
     * bit the money columns in Phase 4; the booleans here are the same
     * trap wearing a different hat.
     *
     * @var array<string, bool|int|string>
     */
    protected $attributes = [
        'renewal_minor' => 0,
        'status' => 'pending',
        'years' => 1,
        'auto_renew' => true,
        'registrar_lock' => true,
        'whois_privacy' => false,
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
     * @return BelongsTo<Tld, $this>
     */
    public function tld(): BelongsTo
    {
        return $this->belongsTo(Tld::class);
    }

    /**
     * @return HasMany<DomainEvent, $this>
     */
    public function events(): HasMany
    {
        return $this->hasMany(DomainEvent::class)->latest('occurred_at');
    }

    public function domainName(): DomainName
    {
        return DomainName::of($this->label, $this->extension);
    }

    /**
     * What an adapter is allowed to know about this domain.
     */
    public function reference(): DomainReference
    {
        return new DomainReference($this->id, $this->domainName(), $this->external_id);
    }

    public function daysUntilExpiry(): ?int
    {
        $expires = $this->expires_on;

        return $expires === null
            ? null
            : (int) CarbonImmutable::now()->startOfDay()->diffInDays($expires->startOfDay(), false);
    }

    public function auditLabel(): string
    {
        return $this->name;
    }

    /**
     * Names that will lapse unless something happens.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    protected function scopeExpiringWithin(Builder $query, int $days): Builder
    {
        return $query
            ->where('status', DomainStatus::Active->value)
            ->whereNotNull('expires_on')
            ->whereDate('expires_on', '<=', CarbonImmutable::now()->addDays($days)->toDateString());
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => DomainStatus::class,
            'years' => 'integer',
            'renewal' => MoneyCast::class.':renewal_minor',
            'registered_on' => 'immutable_date',
            'expires_on' => 'immutable_date',
            'auto_renew' => 'boolean',
            'registrar_lock' => 'boolean',
            'whois_privacy' => 'boolean',
            'nameservers' => 'array',
            'synced_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
