<?php

declare(strict_types=1);

namespace App\Infrastructure\Content\Models;

use App\Domain\Support\ArticleVisibility;
use App\Infrastructure\Organizations\Concerns\BelongsToOrganization;
use App\Support\Audit\Contracts\AuditLabel;
use Carbon\CarbonImmutable;
use Database\Factories\KbArticleFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One answer, written once.
 *
 * The counters are numbers rather than conversations. A "was this helpful"
 * that opens a text box collects complaints nobody reads; two integers tell
 * an operator which article to rewrite.
 *
 * @property string $title
 * @property string $slug
 * @property string|null $excerpt
 * @property string $body
 * @property ArticleVisibility $visibility
 * @property CarbonImmutable|null $published_at
 * @property int $view_count
 * @property int $helpful_count
 * @property int $unhelpful_count
 */
final class KbArticle extends Model implements AuditLabel
{
    use BelongsToOrganization;

    /** @use HasFactory<KbArticleFactory> */
    use HasFactory;

    use HasUlids;

    protected $table = 'kb_articles';

    protected $fillable = [
        'organization_id',
        'category_id',
        'title',
        'slug',
        'excerpt',
        'body',
        'visibility',
        'published_at',
        'position',
    ];

    /**
     * The counters are not fillable on purpose: they move through
     * `increment()`, which is atomic, rather than through a mass assignment
     * that races with itself.
     *
     * @var array<string, int|string>
     */
    protected $attributes = [
        'visibility' => 'public',
        'position' => 0,
        'view_count' => 0,
        'helpful_count' => 0,
        'unhelpful_count' => 0,
    ];

    /**
     * @return BelongsTo<KbCategory, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(KbCategory::class, 'category_id');
    }

    public function auditLabel(): string
    {
        return $this->title;
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    protected function scopeVisible(Builder $query): Builder
    {
        return $query
            ->whereNot('visibility', ArticleVisibility::Draft->value)
            ->whereNotNull('published_at')
            ->where('published_at', '<=', CarbonImmutable::now());
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
            'position' => 'integer',
            'view_count' => 'integer',
            'helpful_count' => 'integer',
            'unhelpful_count' => 'integer',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
