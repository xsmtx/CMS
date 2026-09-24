<?php

declare(strict_types=1);

namespace App\Infrastructure\Tax\Models;

use App\Domain\Tax\TaxRounding;
use App\Infrastructure\Organizations\Concerns\BelongsToOrganization;
use App\Support\Audit\Contracts\AuditLabel;
use Carbon\CarbonImmutable;
use Database\Factories\TaxSettingFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * The handful of answers about tax that are not a rate.
 *
 * Separate from `BrandSetting` on purpose, although both are "settings": a brand
 * is printed by templates a theme author wrote and may hold nothing private
 * (ADR 0036), while these decide arithmetic. Putting them together would put tax
 * policy into a value object whose whole point is that it is safe to render.
 *
 * One row per seller. A reseller that has its own rules has its own answers to
 * these too; one that has neither falls back to the provider's, because
 * `ResolveSeller` walks up.
 *
 * @property string $id
 * @property string $organization_id
 * @property bool $prices_include_tax
 * @property TaxRounding $rounding
 * @property string|null $tax_id_label
 * @property bool $require_tax_id_for_business
 * @property string|null $exemption_note
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
final class TaxSetting extends Model implements AuditLabel
{
    use BelongsToOrganization;

    /** @use HasFactory<TaxSettingFactory> */
    use HasFactory;

    use HasUlids;

    protected $table = 'tax_settings';

    protected $fillable = [
        'organization_id',
        'prices_include_tax',
        'rounding',
        'tax_id_label',
        'require_tax_id_for_business',
        'exemption_note',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'prices_include_tax' => false,
        'rounding' => 'per_line',
        'require_tax_id_for_business' => false,
    ];

    public function auditLabel(): string
    {
        return 'Tax settings';
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'prices_include_tax' => 'boolean',
            'rounding' => TaxRounding::class,
            'require_tax_id_for_business' => 'boolean',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
