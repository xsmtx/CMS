<?php

declare(strict_types=1);

namespace App\Infrastructure\Content\Models;

use App\Domain\Support\ArticleVisibility;
use App\Infrastructure\Organizations\Concerns\BelongsToOrganization;
use App\Support\Audit\Contracts\AuditLabel;
use Carbon\CarbonImmutable;
use Database\Factories\AnnouncementFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Something the operator wants everybody to read.
 *
 * `published_at` in the future is a scheduled post, not a draft — the
 * distinction matters because a draft is unfinished and a scheduled post is
 * finished and waiting.
 *
 * @property string $title
 * @property string $slug
 * @property string $body
 * @property ArticleVisibility $visibility
 * @property CarbonImmutable|null $published_at
 * @property CarbonImmutable|null $expires_at
 * @property bool $is_pinned
 */
final class Announcement extends Model implements AuditLabel
{
    use BelongsToOrganization;

    /** @use HasFactory<AnnouncementFactory> */
    use HasFactory;

    use HasUlids;

    protected $table = 'announcements';

    protected $fillable = [
        'organization_id',
        'title',
        'slug',
        'body',
        'visibility',
        'published_at',
        'expires_at',
        'is_pinned',
    ];

    /**
     * @var array<string, bool|string>
     */
    protected $attributes = [
        'visibility' => 'public',
        'is_pinned' => false,
    ];

    public function auditLabel(): string
    {
        return $this->title;
    }

    /**
     * Live right now: published, not in the future, not expired.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    protected function scopeVisible(Builder $query): Builder
    {
        $now = CarbonImmutable::now();

        return $query
            ->whereNot('visibility', ArticleVisibility::Draft->value)
            ->whereNotNull('published_at')
            ->where('published_at', '<=', $now)
            ->where(fn (Builder $inner) => $inner
                ->whereNull('expires_at')
                ->orWhere('expires_at', '>', $now));
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    protected function scopePubliclyVisible(Builder $query): Builder
    {
        return $query->visible()->where('visibility', ArticleVisibility::Public->value);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'visibility' => ArticleVisibility::class,
            'published_at' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
            'is_pinned' => 'boolean',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
