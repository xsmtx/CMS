<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Application\Content\VisibleContent;
use App\Infrastructure\Content\Models\Announcement;
use App\Infrastructure\Content\Models\KbArticle;
use App\Infrastructure\Content\Models\KbCategory;
use App\Infrastructure\Identity\Models\Contact;
use App\Support\Catalog\StorefrontCurrency;
use App\Support\Identity\CurrentActor;
use App\Support\View\Markdown;
use App\Support\View\StorefrontRenderer;
use Illuminate\Contracts\Support\Renderable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The knowledge base and announcements, as a visitor sees them.
 *
 * Visibility is decided by who is asking: a signed-in customer sees
 * customer-only content, everybody else sees the public set. A draft is
 * never rendered, whoever is asking.
 */
final class StorefrontContentController extends Controller
{
    public function __construct(
        private readonly StorefrontRenderer $renderer,
        private readonly StorefrontCurrency $currency,
        private readonly CurrentActor $actor,
        private readonly Markdown $markdown,
        private readonly VisibleContent $content,
    ) {}

    public function knowledgeBase(Request $request): Renderable
    {
        $query = trim($request->string('q')->toString());

        $articles = $this->content->articles($this->isSignedIn(), $query);

        return $this->renderer->render('knowledge-base', [
            'currency' => $this->currency->current(),
            'currencies' => $this->currency->available(),
            'query' => $query,
            'categories' => KbCategory::query()
                ->where('is_active', true)
                ->orderBy('position')
                ->get()
                ->map(static fn (KbCategory $category): array => [
                    'name' => $category->name,
                    'slug' => $category->slug,
                ])
                ->values()
                ->all(),
            'articles' => $articles
                ->map(fn (KbArticle $article): array => [
                    'title' => $article->title,
                    'slug' => $article->slug,
                    'category' => $article->category?->name,
                    'excerpt' => $article->excerpt ?? $this->markdown->excerpt($article->body),
                ])
                ->values()
                ->all(),
        ]);
    }

    public function article(string $slug): Renderable
    {
        $article = $this->content->article($this->isSignedIn(), $slug);

        if (! $article instanceof KbArticle) {
            throw new NotFoundHttpException;
        }

        // Atomic: two readers at once are two views, not one.
        $article->increment('view_count');

        return $this->renderer->render('knowledge-base-article', [
            'currency' => $this->currency->current(),
            'currencies' => $this->currency->available(),
            'article' => [
                'title' => $article->title,
                'slug' => $article->slug,
                'category' => $article->category?->name,
                // Escaped, then rendered. Being written by a colleague is
                // not a security property.
                'body' => $this->markdown->render($article->body),
                'helpful' => $article->helpful_count,
                'unhelpful' => $article->unhelpful_count,
            ],
        ]);
    }

    public function rate(Request $request, string $slug): RedirectResponse
    {
        $article = $this->content->article($this->isSignedIn(), $slug);

        if (! $article instanceof KbArticle) {
            throw new NotFoundHttpException;
        }

        $article->increment($request->boolean('helpful') ? 'helpful_count' : 'unhelpful_count');

        return back()->with('status', __('support.kb.helpful_thanks'));
    }

    public function announcements(): Renderable
    {
        return $this->renderer->render('announcements', [
            'currency' => $this->currency->current(),
            'currencies' => $this->currency->available(),
            'announcements' => $this->content->announcements($this->isSignedIn())
                ->map(fn (Announcement $announcement): array => [
                    'title' => $announcement->title,
                    'slug' => $announcement->slug,
                    'body' => $this->markdown->render($announcement->body),
                    'publishedAt' => $announcement->published_at?->toIso8601String(),
                    'isPinned' => $announcement->is_pinned,
                ])
                ->values()
                ->all(),
        ]);
    }

    private function isSignedIn(): bool
    {
        return $this->actor->model() instanceof Contact;
    }
}
