<?php

declare(strict_types=1);

namespace App\Infrastructure\Access\Models;

use App\Domain\Access\RoleScope;
use Carbon\CarbonImmutable;
use Database\Factories\PermissionFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Database mirror of a permission declared in the registry.
 *
 * A permission that disappears from code is marked orphaned rather than
 * deleted: existing role assignments stay explainable, and reinstalling the
 * module that declared it restores the grant instead of silently dropping it.
 *
 * @property string $id
 * @property string $slug
 * @property string $group
 * @property RoleScope $scope
 * @property bool $is_high_risk
 * @property string|null $module
 * @property CarbonImmutable|null $orphaned_at
 */
final class Permission extends Model
{
    /** @use HasFactory<PermissionFactory> */
    use HasFactory;

    use HasUlids;

    protected $table = 'permissions';

    protected $fillable = ['slug', 'group', 'scope', 'is_high_risk', 'module', 'orphaned_at'];

    /**
     * @return BelongsToMany<Role, $this>
     */
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'permission_role');
    }

    public function isOrphaned(): bool
    {
        return $this->orphaned_at !== null;
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    protected function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('orphaned_at');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'scope' => RoleScope::class,
            'is_high_risk' => 'boolean',
            'orphaned_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
