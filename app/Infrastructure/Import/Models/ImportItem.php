<?php

declare(strict_types=1);

namespace App\Infrastructure\Import\Models;

use App\Domain\Import\ImportDomain;
use App\Domain\Import\ImportOutcome;
use App\Infrastructure\Organizations\Concerns\BelongsToOrganization;
use Carbon\CarbonImmutable;
use Database\Factories\ImportItemFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What happened to one legacy row, in one run.
 *
 * Kept rather than summarised into a count. **No silent data loss** means an
 * operator can read the four hundred rows that did not come across and decide
 * about each one; a run that stored only totals would tell them the number and
 * nothing else, and the number is the least useful part.
 *
 * No `updated_at`: an item is written once and never revised. A second attempt
 * is a second run.
 *
 * @property ImportDomain $domain
 * @property ImportOutcome $outcome
 * @property CarbonImmutable|null $created_at
 */
final class ImportItem extends Model
{
    use BelongsToOrganization;

    /** @use HasFactory<ImportItemFactory> */
    use HasFactory;

    use HasUlids;

    public const ?string UPDATED_AT = null;

    protected $table = 'import_items';

    protected $fillable = [
        'organization_id',
        'run_id',
        'domain',
        'external_id',
        'outcome',
        'target_type',
        'target_id',
        'label',
        'message',
    ];

    /**
     * @return BelongsTo<ImportRun, $this>
     */
    public function run(): BelongsTo
    {
        return $this->belongsTo(ImportRun::class, 'run_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'domain' => ImportDomain::class,
            'outcome' => ImportOutcome::class,
            'created_at' => 'immutable_datetime',
        ];
    }
}
