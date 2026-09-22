<?php

declare(strict_types=1);

namespace App\Infrastructure\Access\Models;

use App\Domain\Access\RoleScope;
use App\Domain\Access\SystemRole;
use Carbon\CarbonImmutable;
use Database\Factories\RoleFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use RuntimeException;

/**
 * A named bundle of permissions, scoped to staff or to customers.
 *
 * @property string $id
 * @property string $slug
 * @property RoleScope $scope
 * @property string $name
 * @property string|null $description
 * @property bool $is_system
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read Collection<int, Permission> $permissions
 */
final class Role extends Model
{
    /** @use HasFactory<RoleFactory> */
    use HasFactory;

    use HasUlids;

    protected $table = 'roles';

    protected $fillable = ['slug', 'scope', 'name', 'description', 'is_system'];

    /**
     * @return BelongsToMany<Permission, $this>
     */
    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class, 'permission_role');
    }

    public function isSuperAdmin(): bool
    {
        return $this->slug === SystemRole::SuperAdmin->value;
    }

    public function auditLabel(): string
    {
        return $this->name;
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    protected function scopeForScope(Builder $query, RoleScope $scope): Builder
    {
        return $query->where('scope', $scope->value);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'scope' => RoleScope::class,
            'is_system' => 'boolean',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        self::deleting(function (self $role): void {
            if ($role->is_system) {
                throw new RuntimeException("System role [{$role->slug}] cannot be deleted.");
            }
        });
    }
}
