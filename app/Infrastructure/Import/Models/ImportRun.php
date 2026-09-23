<?php

declare(strict_types=1);

namespace App\Infrastructure\Import\Models;

use App\Domain\Import\ImportMode;
use App\Domain\Import\ImportStatus;
use App\Infrastructure\Identity\Models\StaffUser;
use App\Infrastructure\Organizations\Concerns\BelongsToOrganization;
use Carbon\CarbonImmutable;
use Database\Factories\ImportRunFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One attempt at bringing a legacy system across.
 *
 * The row exists before the job reaches a worker (ADR 0032): an import that
 * never started is the failure nobody sees otherwise, and a migration is
 * exactly the operation somebody starts and then goes to lunch.
 *
 * @property ImportMode $mode
 * @property ImportStatus $status
 * @property list<string> $domains
 * @property array<string, int>|null $expected
 * @property array<string, array<string, int>>|null $totals
 * @property CarbonImmutable|null $started_at
 * @property CarbonImmutable|null $finished_at
 * @property CarbonImmutable $created_at
 */
final class ImportRun extends Model
{
    use BelongsToOrganization;

    /** @use HasFactory<ImportRunFactory> */
    use HasFactory;

    use HasUlids;

    protected $table = 'import_runs';

    protected $fillable = [
        'organization_id',
        'source',
        'mode',
        'status',
        'domains',
        'expected',
        'totals',
        'started_by',
        'correlation_id',
        'error',
        'started_at',
        'finished_at',
    ];

    /** @var array<string, string> */
    protected $attributes = ['status' => 'pending'];

    /**
     * @return HasMany<ImportItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(ImportItem::class, 'run_id');
    }

    /**
     * @return BelongsTo<StaffUser, $this>
     */
    public function startedBy(): BelongsTo
    {
        return $this->belongsTo(StaffUser::class, 'started_by');
    }

    /**
     * How many rows reached one outcome, all domains together.
     *
     * Read from the stored totals rather than counted, so the report of a run
     * with twelve thousand items does not count twelve thousand rows.
     */
    public function countOf(string $outcome): int
    {
        $totals = $this->totals ?? [];

        $sum = 0;

        foreach ($totals as $perDomain) {
            $sum += (int) ($perDomain[$outcome] ?? 0);
        }

        return $sum;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'mode' => ImportMode::class,
            'status' => ImportStatus::class,
            'domains' => 'array',
            'expected' => 'array',
            'totals' => 'array',
            'started_at' => 'immutable_datetime',
            'finished_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
