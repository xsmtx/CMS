<?php

declare(strict_types=1);

namespace App\Infrastructure\Network\Models;

use App\Infrastructure\Organizations\Concerns\BelongsToOrganization;
use App\Support\Audit\Contracts\AuditLabel;
use Carbon\CarbonImmutable;
use Database\Factories\VlanFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A broadcast domain, by the number written on it.
 *
 * @property string $id
 * @property string $organization_id
 * @property int $tag
 * @property string $name
 * @property string|null $site
 * @property string|null $note
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
final class Vlan extends Model implements AuditLabel
{
    use BelongsToOrganization;

    /** @use HasFactory<VlanFactory> */
    use HasFactory;

    use HasUlids;

    protected $table = 'vlans';

    protected $fillable = ['organization_id', 'tag', 'name', 'site', 'note'];

    /**
     * @return HasMany<IpPrefixRecord, $this>
     */
    public function prefixes(): HasMany
    {
        return $this->hasMany(IpPrefixRecord::class, 'vlan_id');
    }

    public function auditLabel(): string
    {
        return $this->name.' ('.$this->tag.')';
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'tag' => 'integer',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
