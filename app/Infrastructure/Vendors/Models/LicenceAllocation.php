<?php

declare(strict_types=1);

namespace App\Infrastructure\Vendors\Models;

use App\Infrastructure\Organizations\Concerns\BelongsToOrganization;
use App\Infrastructure\Provisioning\Models\Server;
use App\Support\Audit\Contracts\AuditLabel;
use Carbon\CarbonImmutable;
use Database\Factories\LicenceAllocationFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One seat of a pool, on one machine (§24).
 *
 * **It is a current fact, not a history.** Releasing a seat deletes the row
 * and the audit log records who did it; an append-only table was considered
 * and left out, because the question this family answers is "what are we
 * paying for that nothing is using" and that question is about now. The
 * pool's own seat count is a number an operator edits anyway, so a history of
 * allocations beside an unversioned seat count would be half an answer
 * wearing the shape of a whole one.
 *
 * `server_name` is copied at allocation and `server_id` falls to null when
 * the machine leaves the fleet, which is how a seat attached to something
 * that is no longer there stays visible instead of disappearing with it.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $licence_pool_id
 * @property string|null $server_id
 * @property string $server_name
 * @property string|null $reference
 * @property string|null $note
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
final class LicenceAllocation extends Model implements AuditLabel
{
    use BelongsToOrganization;

    /** @use HasFactory<LicenceAllocationFactory> */
    use HasFactory;

    use HasUlids;

    protected $table = 'licence_allocations';

    protected $fillable = [
        'organization_id',
        'licence_pool_id',
        'server_id',
        'server_name',
        'reference',
        'note',
    ];

    /**
     * @return BelongsTo<LicencePool, $this>
     */
    public function pool(): BelongsTo
    {
        return $this->belongsTo(LicencePool::class, 'licence_pool_id');
    }

    /**
     * @return BelongsTo<Server, $this>
     */
    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    public function auditLabel(): string
    {
        return $this->server_name;
    }
}
