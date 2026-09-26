<?php

declare(strict_types=1);

namespace App\Infrastructure\Reliability\Models;

use App\Domain\Reliability\AlertComparison;
use App\Domain\Reliability\AlertSeverity;
use App\Domain\Reliability\AlertSubject;
use App\Infrastructure\Organizations\Concerns\BelongsToOrganization;
use App\Support\Audit\Contracts\AuditLabel;
use Carbon\CarbonImmutable;
use Database\Factories\AlertRuleFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One question an operator wants asked repeatedly.
 *
 * `threshold_ppm` is parts per million, for the reason `tax_rules.rate_ppm`
 * is: a rule at 0.9 on a ratio and one at 90 on a percentage are the same
 * rule, and a float column would hold 0.899999999 for one of them. The number
 * an operator typed becomes the integer **once**, in the request, and nothing
 * downstream converts it again.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $name
 * @property AlertSubject $subject
 * @property string|null $target
 * @property AlertComparison|null $comparison
 * @property int|null $threshold_ppm
 * @property int $for_minutes
 * @property AlertSeverity $severity
 * @property bool $enabled
 * @property bool $notify
 * @property string|null $note
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
final class AlertRule extends Model implements AuditLabel
{
    use BelongsToOrganization;

    /** @use HasFactory<AlertRuleFactory> */
    use HasFactory;

    use HasUlids;

    protected $fillable = [
        'organization_id',
        'name',
        'subject',
        'target',
        'comparison',
        'threshold_ppm',
        'for_minutes',
        'severity',
        'enabled',
        'notify',
        'note',
    ];

    /**
     * The database defaults, declared here too — a default fills the row and
     * leaves the model in memory without the attribute, and a cast reads that
     * absence as null. It bit the money columns in Phase 4 and the booleans in
     * Phase 7.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'for_minutes' => 0,
        'enabled' => true,
        'notify' => true,
    ];

    /**
     * @return HasMany<Alert, $this>
     */
    public function alerts(): HasMany
    {
        return $this->hasMany(Alert::class);
    }

    /**
     * The threshold as the number the comparison uses.
     *
     * Divided back out of parts per million here, once, so no caller has to
     * remember the unit — and null where the subject has no number, which is
     * a real answer rather than a zero.
     */
    public function threshold(): ?float
    {
        return $this->threshold_ppm === null ? null : $this->threshold_ppm / 1_000_000;
    }

    public function auditLabel(): string
    {
        return $this->name;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'subject' => AlertSubject::class,
            'comparison' => AlertComparison::class,
            'severity' => AlertSeverity::class,
            'threshold_ppm' => 'integer',
            'for_minutes' => 'integer',
            'enabled' => 'boolean',
            'notify' => 'boolean',
        ];
    }
}
