<?php

declare(strict_types=1);

namespace App\Infrastructure\Domains\Models;

use App\Domain\Catalog\CatalogStatus;
use App\Domain\Domains\DomainAction;
use App\Domain\Shared\Money;
use App\Infrastructure\Organizations\Concerns\BelongsToOrganization;
use App\Support\Audit\Contracts\AuditLabel;
use Carbon\CarbonImmutable;
use Database\Factories\TldFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An extension this installation sells.
 *
 * `min_years` and `max_years` are the registry's rules, not a preference: a
 * `.com.tr` cannot be registered for one year and a `.de` cannot be
 * registered for ten, and a customer told otherwise finds out after paying.
 *
 * @property string $id
 * @property string $extension
 * @property string|null $registrar
 * @property int $min_years
 * @property int $max_years
 * @property bool $allows_transfer
 * @property bool $allows_whois_privacy
 * @property bool $requires_epp_code
 * @property bool $supports_idn
 * @property CatalogStatus $status
 * @property int $position
 * @property int $grace_days
 * @property int $redemption_days
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
final class Tld extends Model implements AuditLabel
{
    use BelongsToOrganization;

    /** @use HasFactory<TldFactory> */
    use HasFactory;

    use HasUlids;

    protected $table = 'tlds';

    protected $fillable = [
        'organization_id',
        'extension',
        'registrar',
        'min_years',
        'max_years',
        'allows_transfer',
        'allows_whois_privacy',
        'requires_epp_code',
        'supports_idn',
        'status',
        'position',
        'grace_days',
        'redemption_days',
    ];

    /**
     * @return HasMany<TldPrice, $this>
     */
    public function prices(): HasMany
    {
        return $this->hasMany(TldPrice::class);
    }

    /**
     * @return HasMany<Domain, $this>
     */
    public function domains(): HasMany
    {
        return $this->hasMany(Domain::class);
    }

    /**
     * What this costs, or null when it is not sold that way.
     *
     * Absence and zero are different statements
     * ([ADR 0019](../../../../docs/adr/0019-price-matrix.md)): one means
     * the operator does not offer it, the other means they offer it free.
     */
    public function priceFor(DomainAction $action, int $years, string $currencyCode): ?Money
    {
        $price = $this->prices
            ->first(fn (TldPrice $price): bool => $price->action === $action
                && $price->years === $years
                && $price->currency_code === mb_strtoupper($currencyCode));

        return $price?->amount;
    }

    /**
     * The terms an operator has priced for this action and currency.
     *
     * @return list<int>
     */
    public function termsFor(DomainAction $action, string $currencyCode): array
    {
        $years = $this->prices
            ->filter(fn (TldPrice $price): bool => $price->action === $action
                && $price->currency_code === mb_strtoupper($currencyCode))
            ->map(static fn (TldPrice $price): int => $price->years)
            ->unique()
            ->sort()
            ->values()
            ->all();

        return array_values(array_filter(
            $years,
            fn (int $value): bool => $value >= $this->min_years && $value <= $this->max_years,
        ));
    }

    public function allowsTerm(int $years): bool
    {
        return $years >= $this->min_years && $years <= $this->max_years;
    }

    public function auditLabel(): string
    {
        return '.'.$this->extension;
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    protected function scopeSellable(Builder $query): Builder
    {
        return $query->where('status', CatalogStatus::Active->value);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => CatalogStatus::class,
            'min_years' => 'integer',
            'max_years' => 'integer',
            'position' => 'integer',
            'grace_days' => 'integer',
            'redemption_days' => 'integer',
            'allows_transfer' => 'boolean',
            'allows_whois_privacy' => 'boolean',
            'requires_epp_code' => 'boolean',
            'supports_idn' => 'boolean',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
