<?php

declare(strict_types=1);

namespace App\Infrastructure\Ai\Models;

use App\Domain\Ai\AiFeature;
use App\Infrastructure\Organizations\Concerns\BelongsToOrganization;
use Carbon\CarbonImmutable;
use Database\Factories\AiUsageFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * One call to a provider, and what it cost (ADR 0050).
 *
 * **It does not hold the completion**, and that absence is the design. A
 * draft nobody sent is not worth keeping; one that was sent is already the
 * reply. Keeping both would put a customer's correspondence in a second table
 * that no retention policy covers and no erasure request finds.
 *
 * A refusal is recorded as well as a success, because "the vendor would not
 * answer" is exactly what somebody is looking for when they ask why the
 * button stopped working.
 *
 * @property string $id
 * @property string $organization_id
 * @property string|null $staff_user_id
 * @property AiFeature $feature
 * @property string $provider_key
 * @property string $model
 * @property int|null $prompt_tokens
 * @property int|null $completion_tokens
 * @property string $outcome
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
final class AiUsage extends Model
{
    use BelongsToOrganization;

    /** @use HasFactory<AiUsageFactory> */
    use HasFactory;

    use HasUlids;

    public const string OutcomeAnswered = 'answered';

    public const string OutcomeRefused = 'refused';

    protected $table = 'ai_usages';

    protected $fillable = [
        'organization_id',
        'staff_user_id',
        'feature',
        'provider_key',
        'model',
        'prompt_tokens',
        'completion_tokens',
        'outcome',
    ];

    public function tokens(): ?int
    {
        if ($this->prompt_tokens === null && $this->completion_tokens === null) {
            return null;
        }

        return ($this->prompt_tokens ?? 0) + ($this->completion_tokens ?? 0);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'feature' => AiFeature::class,
            'prompt_tokens' => 'integer',
            'completion_tokens' => 'integer',
        ];
    }
}
