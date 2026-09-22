<?php

declare(strict_types=1);

namespace App\Infrastructure\Organizations\Models;

use App\Domain\Organizations\Exceptions\InvalidOrganizationHierarchy;
use App\Domain\Organizations\OrganizationType;
use App\Infrastructure\Organizations\OrganizationBoundary;
use App\Support\Audit\Contracts\AuditLabel;
use Carbon\CarbonImmutable;
use Database\Factories\OrganizationFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The ownership boundary every other record hangs from.
 *
 * `path` is a materialised ancestor chain (`/<root>/<child>/<leaf>/`). It
 * turns "everything this organization can see" into a single indexed prefix
 * match instead of a recursive query on every authorization check, which
 * matters because that check runs on every listing in the product.
 *
 * @property string $id
 * @property string|null $parent_id
 * @property OrganizationType $type
 * @property string $name
 * @property string $slug
 * @property string $path
 * @property bool $is_active
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
final class Organization extends Model implements AuditLabel
{
    /** @use HasFactory<OrganizationFactory> */
    use HasFactory;

    use HasUlids;

    protected $table = 'organizations';

    protected $fillable = ['parent_id', 'type', 'name', 'slug', 'is_active'];

    /**
     * @return Builder<self>
     */
    public static function provider(): Builder
    {
        return self::query()->where('type', OrganizationType::Provider->value);
    }

    /**
     * @return BelongsTo<self, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /**
     * @return HasMany<self, $this>
     */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function isProvider(): bool
    {
        return $this->type === OrganizationType::Provider;
    }

    /**
     * Whether this organization owns, directly or indirectly, the given one.
     */
    public function owns(self $other): bool
    {
        return str_starts_with($other->path, $this->path);
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
            'type' => OrganizationType::class,
            'is_active' => 'boolean',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        self::addGlobalScope('organization', static function (Builder $query): void {
            app(OrganizationBoundary::class)->applyToSelf($query);
        });

        self::creating(static function (self $organization): void {
            $organization->guardHierarchy();
            $organization->path = $organization->buildPath();
        });

        self::updating(static function (self $organization): void {
            if ($organization->isDirty('parent_id')) {
                $organization->guardHierarchy();
                $organization->path = $organization->buildPath();
            }
        });
    }

    private function guardHierarchy(): void
    {
        $parent = $this->parent_id === null
            ? null
            : self::withoutGlobalScope('organization')->findOrFail($this->parent_id);

        if ($this->type === OrganizationType::Provider) {
            if ($parent !== null) {
                throw InvalidOrganizationHierarchy::providerMustBeRoot();
            }

            return;
        }

        if ($parent === null) {
            throw InvalidOrganizationHierarchy::nonProviderMustHaveParent($this->type);
        }

        if (! in_array($this->type, $parent->type->permittedChildTypes(), strict: true)) {
            throw InvalidOrganizationHierarchy::cannotNest($parent->type, $this->type);
        }
    }

    private function buildPath(): string
    {
        $id = $this->id ?? $this->newUniqueId();
        $this->id = $id;

        if ($this->parent_id === null) {
            return '/'.$id.'/';
        }

        $parent = self::withoutGlobalScope('organization')->findOrFail($this->parent_id);

        return $parent->path.$id.'/';
    }
}
