<?php

declare(strict_types=1);

namespace App\Infrastructure\Tax\Models;

use App\Domain\Shared\Money;
use App\Domain\Tax\TaxAppliesTo;
use App\Domain\Tax\TaxCustomerKind;
use App\Infrastructure\Organizations\Concerns\BelongsToOrganization;
use App\Support\Audit\Contracts\AuditLabel;
use Carbon\CarbonImmutable;
use Database\Factories\TaxRuleFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * One thing an operator has been told to charge.
 *
 * The row says what to charge and where, and nothing about why (ADR 0045). Core
 * ships no rates and knows no country's law; this is where somebody who does
 * writes it down.
 *
 * `rate_ppm` is parts per million and an integer, because a rate is on a monetary
 * path. Basis points were the obvious unit and could not hold Quebec's 9.975%,
 * which a test found; parts per million give four decimal places of a percent and
 * still no float. `percentage()` turns it into the decimal string
 * `Money::percentage()` wants — the one place in this platform that multiplies
 * money — so the rate never becomes a float on the way to a cent.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $name
 * @property string|null $country_code
 * @property string|null $region_code
 * @property string|null $postcode_pattern
 * @property int $rate_ppm
 * @property int $level
 * @property bool $compound
 * @property TaxAppliesTo $applies_to
 * @property TaxCustomerKind $customer_kind
 * @property bool $exempts_validated_business
 * @property string|null $exemption_note
 * @property int $priority
 * @property CarbonImmutable|null $starts_on
 * @property CarbonImmutable|null $ends_on
 * @property bool $is_active
 * @property string|null $notes
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
final class TaxRule extends Model implements AuditLabel
{
    use BelongsToOrganization;

    /** @use HasFactory<TaxRuleFactory> */
    use HasFactory;

    use HasUlids;

    protected $table = 'tax_rules';

    protected $fillable = [
        'organization_id',
        'name',
        'country_code',
        'region_code',
        'postcode_pattern',
        'rate_ppm',
        'level',
        'compound',
        'applies_to',
        'customer_kind',
        'exempts_validated_business',
        'exemption_note',
        'priority',
        'starts_on',
        'ends_on',
        'is_active',
        'notes',
    ];

    /**
     * The database defaults, declared again — a default fills the row and leaves
     * the model in memory without the attribute, and a cast reads that absence
     * as null. It bit the money columns in Phase 4 and the booleans in Phase 7.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'level' => 1,
        'compound' => false,
        'applies_to' => 'all',
        'customer_kind' => 'all',
        'exempts_validated_business' => false,
        'priority' => 0,
        'is_active' => true,
    ];

    /**
     * The rate as the decimal string `Money::percentage()` takes.
     *
     * At least two decimal places so a whole rate prints the same way every
     * time — 200,000 reads as `20.00` — and up to four when the rate needs
     * them, so 99,750 reads as `9.975` rather than being rounded to a rate
     * Quebec does not charge.
     */
    public function percentage(): string
    {
        $full = number_format($this->rate_ppm / 10_000, 4, '.', '');
        $trimmed = rtrim($full, '0');

        // Never fewer than two: `20.` and `20.0` are the same number and read
        // as a typing mistake on a tax document.
        return str_ends_with($trimmed, '.') || strlen($trimmed) - (int) strrpos($trimmed, '.') <= 2
            ? number_format($this->rate_ppm / 10_000, 2, '.', '')
            : $trimmed;
    }

    public function charge(Money $amount): Money
    {
        return $amount->percentage($this->percentage());
    }

    /**
     * How specific this rule is, which is how the matcher orders them.
     *
     * Region beats country beats nothing, and a postcode pattern beats a bare
     * region. An operator writing a national rate and one province expects the
     * province to win, and a platform that made them guess would be a platform
     * whose tax nobody trusts.
     */
    public function specificity(): int
    {
        return ($this->country_code !== null ? 4 : 0)
            + ($this->region_code !== null ? 2 : 0)
            + ($this->postcode_pattern !== null ? 1 : 0);
    }

    public function appliesOn(CarbonImmutable $date): bool
    {
        if ($this->starts_on !== null && $date->lt($this->starts_on)) {
            return false;
        }

        return $this->ends_on === null || ! $date->gt($this->ends_on);
    }

    /**
     * Whether a postcode matches, with `*` meaning "and anything after this".
     *
     * Deliberately not a regular expression: an operator types postcodes, and a
     * regex in this column is a denial of service somebody wrote by accident.
     */
    public function matchesPostcode(?string $postcode): bool
    {
        $pattern = $this->postcode_pattern;

        if ($pattern === null || trim($pattern) === '') {
            return true;
        }

        if ($postcode === null || trim($postcode) === '') {
            return false;
        }

        $needle = strtoupper(str_replace(' ', '', $postcode));
        $pattern = strtoupper(str_replace(' ', '', $pattern));

        if (! str_contains($pattern, '*')) {
            return $needle === $pattern;
        }

        return str_starts_with($needle, rtrim(strstr($pattern, '*', true) ?: '', '*'));
    }

    public function auditLabel(): string
    {
        return $this->name.' '.$this->percentage().'%';
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    protected function scopeUsable(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'rate_ppm' => 'integer',
            'level' => 'integer',
            'compound' => 'boolean',
            'applies_to' => TaxAppliesTo::class,
            'customer_kind' => TaxCustomerKind::class,
            'exempts_validated_business' => 'boolean',
            'priority' => 'integer',
            'starts_on' => 'immutable_date',
            'ends_on' => 'immutable_date',
            'is_active' => 'boolean',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
