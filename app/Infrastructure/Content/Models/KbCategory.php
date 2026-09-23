<?php

declare(strict_types=1);

namespace App\Infrastructure\Content\Models;

use App\Infrastructure\Organizations\Concerns\BelongsToOrganization;
use App\Support\Audit\Contracts\AuditLabel;
use Database\Factories\KbCategoryFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $name
 * @property string $slug
 * @property int $position
 * @property bool $is_active
 */
final class KbCategory extends Model implements AuditLabel
{
    use BelongsToOrganization;

    /** @use HasFactory<KbCategoryFactory> */
    use HasFactory;

    use HasUlids;

    protected $table = 'kb_categories';

    protected $fillable = ['organization_id', 'name', 'slug', 'description', 'position', 'is_active'];

    /**
     * @var array<string, bool|int>
     */
    protected $attributes = ['position' => 0, 'is_active' => true];

    /**
     * @return HasMany<KbArticle, $this>
     */
    public function articles(): HasMany
    {
        return $this->hasMany(KbArticle::class, 'category_id')->orderBy('position');
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
            'position' => 'integer',
            'is_active' => 'boolean',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
