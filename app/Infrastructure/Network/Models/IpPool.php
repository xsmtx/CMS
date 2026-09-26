<?php

declare(strict_types=1);

namespace App\Infrastructure\Network\Models;

use App\Domain\Network\IpFamily;
use App\Domain\Network\PoolPurpose;
use App\Infrastructure\Organizations\Concerns\BelongsToOrganization;
use App\Support\Audit\Contracts\AuditLabel;
use Carbon\CarbonImmutable;
use Database\Factories\IpPoolFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A named group of prefixes, which is how an operator thinks about address
 * space before they think about any particular network.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $name
 * @property IpFamily $family
 * @property PoolPurpose $purpose
 * @property string|null $note
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
final class IpPool extends Model implements AuditLabel
{
    use BelongsToOrganization;

    /** @use HasFactory<IpPoolFactory> */
    use HasFactory;

    use HasUlids;

    protected $table = 'ip_pools';

    protected $fillable = ['organization_id', 'name', 'family', 'purpose', 'note'];

    /**
     * The column defaults declared again: a default fills the row and leaves
     * the model in memory without the attribute, and a cast reads that absence
     * as null.
     *
     * @var array<string, string>
     */
    protected $attributes = ['purpose' => PoolPurpose::Customer->value];

    /**
     * @return HasMany<IpPrefixRecord, $this>
     */
    public function prefixes(): HasMany
    {
        return $this->hasMany(IpPrefixRecord::class, 'ip_pool_id');
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
            'family' => IpFamily::class,
            'purpose' => PoolPurpose::class,
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
