<?php

declare(strict_types=1);

namespace App\Infrastructure\Security\Models;

use App\Domain\Security\FindingSeverity;
use App\Domain\Security\ZoneCheck;
use App\Infrastructure\Domains\Models\Domain;
use App\Infrastructure\Organizations\Concerns\BelongsToOrganization;
use App\Support\Audit\Contracts\AuditLabel;
use Carbon\CarbonImmutable;
use Database\Factories\ZoneFindingFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One thing wrong with a zone, for as long as it is wrong (§8).
 *
 * The same shape as an alert, and deliberately: raised once, kept while it
 * stays true, cleared rather than deleted when somebody fixes the zone. An
 * operator asking "when did we fix that" gets an answer.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $domain_id
 * @property ZoneCheck $check
 * @property FindingSeverity $severity
 * @property string $source
 * @property CarbonImmutable $first_seen_at
 * @property CarbonImmutable $last_seen_at
 * @property CarbonImmutable|null $cleared_at
 * @property string $cleared_token
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
final class ZoneFinding extends Model implements AuditLabel
{
    use BelongsToOrganization;

    /** @use HasFactory<ZoneFindingFactory> */
    use HasFactory;

    use HasUlids;

    protected $fillable = [
        'organization_id',
        'domain_id',
        'check',
        'severity',
        'source',
        'first_seen_at',
        'last_seen_at',
        'cleared_at',
        'cleared_token',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'cleared_token' => '',
    ];

    /**
     * @return BelongsTo<Domain, $this>
     */
    public function domain(): BelongsTo
    {
        return $this->belongsTo(Domain::class);
    }

    public function isOpen(): bool
    {
        return $this->cleared_at === null;
    }

    public function auditLabel(): string
    {
        return $this->check->value;
    }

    /**
     * Still true.
     *
     * @param  Builder<ZoneFinding>  $query
     * @return Builder<ZoneFinding>
     */
    protected function scopeOpen(Builder $query): Builder
    {
        return $query->whereNull('cleared_at');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'check' => ZoneCheck::class,
            'severity' => FindingSeverity::class,
            'first_seen_at' => 'immutable_datetime',
            'last_seen_at' => 'immutable_datetime',
            'cleared_at' => 'immutable_datetime',
        ];
    }
}
