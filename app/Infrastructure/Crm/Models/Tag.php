<?php

declare(strict_types=1);

namespace App\Infrastructure\Crm\Models;

use App\Infrastructure\Organizations\Concerns\BelongsToOrganization;
use App\Support\Audit\Contracts\AuditLabel;
use Database\Factories\TagFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * A label in an organization's own vocabulary.
 *
 * @property string $id
 * @property string $name
 * @property string $slug
 * @property string|null $color
 */
final class Tag extends Model implements AuditLabel
{
    use BelongsToOrganization;

    /** @use HasFactory<TagFactory> */
    use HasFactory;
    use HasUlids;

    protected $table = 'tags';

    protected $fillable = ['organization_id', 'name', 'slug', 'color'];

    public function auditLabel(): string
    {
        return $this->name;
    }

    protected static function booted(): void
    {
        self::saving(static function (self $tag): void {
            if ($tag->slug === null || $tag->slug === '') {
                $tag->slug = Str::slug($tag->name);
            }
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
