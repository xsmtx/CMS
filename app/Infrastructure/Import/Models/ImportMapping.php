<?php

declare(strict_types=1);

namespace App\Infrastructure\Import\Models;

use App\Domain\Import\ImportDomain;
use App\Infrastructure\Organizations\Concerns\BelongsToOrganization;
use Database\Factories\ImportMappingFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * `(source, domain, external_id) → (target_type, target_id)`.
 *
 * The one table that makes an import safe to run twice, and it outlives the run
 * that created it: "which of my WHMCS clients came across" is a question asked
 * months later, and an answer that lived only inside a finished run would be an
 * answer somebody has to reconstruct.
 *
 * A table rather than a convention, deliberately. Matching on something like an
 * email address would silently merge two customers who share one, which is the
 * commonest way a migration loses data without anybody noticing.
 *
 * @property ImportDomain $domain
 */
final class ImportMapping extends Model
{
    use BelongsToOrganization;

    /** @use HasFactory<ImportMappingFactory> */
    use HasFactory;

    use HasUlids;

    protected $table = 'import_mappings';

    protected $fillable = [
        'organization_id',
        'source',
        'domain',
        'external_id',
        'target_type',
        'target_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'domain' => ImportDomain::class,
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
