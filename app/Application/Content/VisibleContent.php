<?php

declare(strict_types=1);

namespace App\Application\Content;

use App\Infrastructure\Content\Models\Announcement;
use App\Infrastructure\Content\Models\KbArticle;
use Illuminate\Support\Collection;

/**
 * What a given viewer is allowed to read.
 *
 * The rule lives here rather than in a controller because there are four
 * places that ask it — the knowledge base, an article, the announcements
 * page and the client dashboard — and four copies of "published, not a
 * draft, and public unless they are signed in" is three chances to get it
 * wrong.
 *
 * A draft is never returned, whoever is asking. `customers` is not a
 * secret-keeping boundary: it is for content that only means anything once
 * somebody has an account. Anything that would genuinely harm the business
 * if published does not belong in a knowledge base at all.
 */
final readonly class VisibleContent
{
    /**
     * @return Collection<int, KbArticle>
     */
    public function articles(bool $signedIn, ?string $search = null, int $limit = 50): Collection
    {
        $query = $signedIn ? KbArticle::query()->visible() : KbArticle::query()->publiclyVisible();

        if ($search !== null && $search !== '') {
            $query->whereFullText(['title', 'body'], $search);
        }

        return $query->with('category')->orderBy('position')->limit($limit)->get();
    }

    public function article(bool $signedIn, string $slug): ?KbArticle
    {
        $query = $signedIn ? KbArticle::query()->visible() : KbArticle::query()->publiclyVisible();

        return $query->with('category')->where('slug', $slug)->first();
    }

    /**
     * @return Collection<int, Announcement>
     */
    public function announcements(bool $signedIn, int $limit = 25): Collection
    {
        $query = $signedIn
            ? Announcement::query()->visible()
            : Announcement::query()->publiclyVisible();

        return $query
            // Pinned first, then newest. An operator pins the thing they
            // need everybody to see regardless of when it was written.
            ->orderByDesc('is_pinned')
            ->orderByDesc('published_at')
            ->limit($limit)
            ->get();
    }
}
