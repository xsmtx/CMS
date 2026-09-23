<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Support\ArticleVisibility;
use App\Http\Controllers\Controller;
use App\Http\Requests\Support\AnnouncementRequest;
use App\Http\Requests\Support\KbArticleRequest;
use App\Infrastructure\Content\Models\Announcement;
use App\Infrastructure\Content\Models\KbArticle;
use App\Infrastructure\Content\Models\KbCategory;
use App\Support\Audit\Facades\Audit;
use App\Support\Errors\ForbiddenException;
use App\Support\Identity\CurrentActor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Announcements and the knowledge base.
 *
 * One controller because they are the same shape — a title, a body, a
 * visibility and a publish date — and an operator writing one has the
 * other in mind.
 */
final class ContentController extends Controller
{
    public function __construct(private readonly CurrentActor $actor) {}

    public function announcements(): Response
    {
        $this->authorizeFor('content.announcements.manage');

        return Inertia::render('Admin/Content/Announcements', [
            'announcements' => Announcement::query()
                ->latest('published_at')
                ->get()
                ->map(static fn (Announcement $announcement): array => [
                    'id' => $announcement->id,
                    'title' => $announcement->title,
                    'body' => $announcement->body,
                    'visibility' => $announcement->visibility->value,
                    'publishedAt' => $announcement->published_at?->toIso8601String(),
                    'expiresAt' => $announcement->expires_at?->toIso8601String(),
                    'isPinned' => $announcement->is_pinned,
                    'isScheduled' => $announcement->published_at?->isFuture() ?? false,
                ])
                ->values()
                ->all(),
            'visibilities' => $this->visibilities(),
        ]);
    }

    public function storeAnnouncement(AnnouncementRequest $request): RedirectResponse
    {
        $this->authorizeFor('content.announcements.manage');

        $announcement = Announcement::query()->create($this->announcementAttributes($request));

        Audit::action('content.announcement.created')
            ->by($this->actor->model())
            ->on($announcement)
            ->write();

        return back()->with('status', __('support.announcements.saved'));
    }

    public function updateAnnouncement(
        AnnouncementRequest $request,
        Announcement $announcement,
    ): RedirectResponse {
        $this->authorizeFor('content.announcements.manage');

        $announcement->update($this->announcementAttributes($request, $announcement));

        Audit::action('content.announcement.updated')
            ->by($this->actor->model())
            ->on($announcement)
            ->write();

        return back()->with('status', __('support.announcements.saved'));
    }

    public function destroyAnnouncement(Announcement $announcement): RedirectResponse
    {
        $this->authorizeFor('content.announcements.manage');

        $announcement->delete();

        Audit::action('content.announcement.deleted')
            ->by($this->actor->model())
            ->on($announcement)
            ->write();

        return back()->with('status', __('support.announcements.deleted'));
    }

    public function articles(): Response
    {
        $this->authorizeFor('content.kb.manage');

        return Inertia::render('Admin/Content/Articles', [
            'categories' => KbCategory::query()
                ->withCount('articles')
                ->orderBy('position')
                ->get()
                ->map(static fn (KbCategory $category): array => [
                    'id' => $category->id,
                    'name' => $category->name,
                    'slug' => $category->slug,
                    'articles' => $category->articles_count,
                ])
                ->values()
                ->all(),
            'articles' => KbArticle::query()
                ->with('category')
                ->orderBy('position')
                ->get()
                ->map(static fn (KbArticle $article): array => [
                    'id' => $article->id,
                    'title' => $article->title,
                    'body' => $article->body,
                    'excerpt' => $article->excerpt,
                    'categoryId' => $article->category_id,
                    'category' => $article->category?->name,
                    'visibility' => $article->visibility->value,
                    'publishedAt' => $article->published_at?->toIso8601String(),
                    'views' => $article->view_count,
                    'helpful' => $article->helpful_count,
                    'unhelpful' => $article->unhelpful_count,
                ])
                ->values()
                ->all(),
            'visibilities' => $this->visibilities(),
        ]);
    }

    public function storeArticle(KbArticleRequest $request): RedirectResponse
    {
        $this->authorizeFor('content.kb.manage');

        $article = KbArticle::query()->create($this->articleAttributes($request));

        Audit::action('content.article.created')->by($this->actor->model())->on($article)->write();

        return back()->with('status', __('support.kb.saved'));
    }

    public function updateArticle(KbArticleRequest $request, KbArticle $article): RedirectResponse
    {
        $this->authorizeFor('content.kb.manage');

        $article->update($this->articleAttributes($request, $article));

        Audit::action('content.article.updated')->by($this->actor->model())->on($article)->write();

        return back()->with('status', __('support.kb.saved'));
    }

    public function destroyArticle(KbArticle $article): RedirectResponse
    {
        $this->authorizeFor('content.kb.manage');

        $article->delete();

        Audit::action('content.article.deleted')->by($this->actor->model())->on($article)->write();

        return back()->with('status', __('support.kb.deleted'));
    }

    public function storeCategory(Request $request): RedirectResponse
    {
        $this->authorizeFor('content.kb.manage');

        $name = (string) $request->input('name');

        KbCategory::query()->create([
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(4)),
            'description' => $request->input('description'),
            'position' => (int) $request->input('position', 0),
        ]);

        return back()->with('status', __('support.kb.category_saved'));
    }

    /**
     * @return array<string, mixed>
     */
    private function announcementAttributes(
        AnnouncementRequest $request,
        ?Announcement $existing = null,
    ): array {
        $title = $request->string('title')->toString();

        return [
            'title' => $title,
            // The slug is generated once and then left alone: a link
            // somebody shared must keep working after a typo is fixed.
            'slug' => $existing === null
                ? Str::slug($title).'-'.Str::lower(Str::random(4))
                : $existing->slug,
            'body' => $request->string('body')->toString(),
            'visibility' => $request->string('visibility')->toString(),
            'published_at' => $request->input('published_at'),
            'expires_at' => $request->input('expires_at'),
            'is_pinned' => $request->boolean('is_pinned'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function articleAttributes(KbArticleRequest $request, ?KbArticle $existing = null): array
    {
        $title = $request->string('title')->toString();

        return [
            'category_id' => $request->input('category_id'),
            'title' => $title,
            'slug' => $existing === null
                ? Str::slug($title).'-'.Str::lower(Str::random(4))
                : $existing->slug,
            'excerpt' => $request->input('excerpt'),
            'body' => $request->string('body')->toString(),
            'visibility' => $request->string('visibility')->toString(),
            'published_at' => $request->input('published_at'),
            'position' => (int) $request->input('position', 0),
        ];
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    private function visibilities(): array
    {
        return array_values(array_map(
            static fn (ArticleVisibility $visibility): array => [
                'value' => $visibility->value,
                'label' => (string) __($visibility->labelKey()),
            ],
            ArticleVisibility::cases(),
        ));
    }

    private function authorizeFor(string $permission): void
    {
        if (! $this->actor->can($permission)) {
            throw new ForbiddenException(__('support.tickets.not_permitted'));
        }
    }
}
