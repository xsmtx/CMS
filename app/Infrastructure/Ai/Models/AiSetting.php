<?php

declare(strict_types=1);

namespace App\Infrastructure\Ai\Models;

use App\Domain\Ai\AiFeature;
use App\Infrastructure\Organizations\Concerns\BelongsToOrganization;
use Carbon\CarbonImmutable;
use Database\Factories\AiSettingFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Whether this seller uses an assistant, and for what (ADR 0050).
 *
 * A seller with no row has no assistant, and **no row is written on read** —
 * the rule `BillingSettings` learned: a default written to the database is
 * terms nobody agreed to, frozen where the next deploy cannot reach them. Here
 * it would be worse, because the default is "do not send our customers' words
 * to a vendor" and a row saying so is a row somebody could flip without
 * noticing it had never been a decision.
 *
 * @property string $id
 * @property string $organization_id
 * @property string|null $provider_key
 * @property list<string>|null $enabled_features
 * @property string|null $instructions
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
final class AiSetting extends Model
{
    use BelongsToOrganization;

    /** @use HasFactory<AiSettingFactory> */
    use HasFactory;

    use HasUlids;

    protected $table = 'ai_settings';

    protected $fillable = [
        'organization_id',
        'provider_key',
        'enabled_features',
        'instructions',
    ];

    public function allows(AiFeature $feature): bool
    {
        return $this->provider_key !== null
            && in_array($feature->value, $this->enabled_features ?? [], strict: true);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['enabled_features' => 'array'];
    }
}
