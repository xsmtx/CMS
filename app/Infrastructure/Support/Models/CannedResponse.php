<?php

declare(strict_types=1);

namespace App\Infrastructure\Support\Models;

use App\Infrastructure\Organizations\Concerns\BelongsToOrganization;
use App\Support\Audit\Contracts\AuditLabel;
use Database\Factories\CannedResponseFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Something an agent types often enough to be worth keeping.
 *
 * @property string $name
 * @property string $body
 * @property int $used_count
 */
final class CannedResponse extends Model implements AuditLabel
{
    use BelongsToOrganization;

    /** @use HasFactory<CannedResponseFactory> */
    use HasFactory;

    use HasUlids;

    protected $table = 'canned_responses';

    protected $fillable = ['organization_id', 'department_id', 'name', 'body', 'used_count'];

    /**
     * @var array<string, int>
     */
    protected $attributes = ['used_count' => 0];

    /**
     * @return BelongsTo<Department, $this>
     */
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
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
            'used_count' => 'integer',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
