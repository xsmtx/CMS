<?php

declare(strict_types=1);

use App\Application\Content\VisibleContent;
use App\Infrastructure\Content\Models\Announcement;
use App\Infrastructure\Content\Models\KbArticle;
use App\Support\View\Markdown;
use Database\Seeders\ProviderOrganizationSeeder;

beforeEach(function (): void {
    $this->seed(ProviderOrganizationSeeder::class);

    $this->content = app(VisibleContent::class);
});

it('never returns a draft, to anyone', function (): void {
    KbArticle::factory()->draft()->create(['title' => 'Half written']);

    expect($this->content->articles(signedIn: false))->toHaveCount(0)
        ->and($this->content->articles(signedIn: true))->toHaveCount(0);
});

it('hides a customers-only article from the public and shows it to an account', function (): void {
    $article = KbArticle::factory()->customersOnly()->create();

    expect($this->content->articles(signedIn: false)->pluck('id'))->not->toContain($article->id)
        ->and($this->content->articles(signedIn: true)->pluck('id'))->toContain($article->id);
});

it('refuses an article by slug when the viewer may not read it', function (): void {
    $article = KbArticle::factory()->customersOnly()->create();

    expect($this->content->article(signedIn: false, slug: $article->slug))->toBeNull()
        ->and($this->content->article(signedIn: true, slug: $article->slug))->not->toBeNull();
});

it('does not publish an announcement before its date', function (): void {
    Announcement::factory()->scheduled()->create();

    expect($this->content->announcements(signedIn: true))->toHaveCount(0);
});

it('stops showing an announcement once it has expired', function (): void {
    Announcement::factory()->expired()->create();

    expect($this->content->announcements(signedIn: true))->toHaveCount(0);
});

it('puts a pinned announcement first regardless of its date', function (): void {
    Announcement::factory()->create(['published_at' => now()->subMinute(), 'title' => 'Newest']);
    Announcement::factory()->create([
        'published_at' => now()->subMonth(),
        'is_pinned' => true,
        'title' => 'Read this one',
    ]);

    expect($this->content->announcements(signedIn: true)->first()?->title)->toBe('Read this one');
});

it('renders an article body as text, never as markup', function (): void {
    $html = app(Markdown::class)->render('Hello <script>alert(1)</script> **world**');

    expect($html)->not->toContain('<script>')
        ->toContain('&lt;script&gt;')
        // The Markdown a knowledge base needs still works.
        ->toContain('<strong>world</strong>');
});

it('refuses a javascript link in an article body', function (): void {
    $html = app(Markdown::class)->render('[click](javascript:alert(1))');

    expect($html)->not->toContain('javascript:');
});

it('produces an excerpt with no markup in it at all', function (): void {
    $excerpt = app(Markdown::class)->excerpt("# Title\n\nA <b>body</b> with *emphasis*.");

    expect($excerpt)->not->toContain('<')
        ->toContain('emphasis');
});

it('renders the knowledge base without the drafts', function (): void {
    KbArticle::factory()->create(['title' => 'Pointing your domain at us']);
    KbArticle::factory()->draft()->create(['title' => 'Internal runbook']);

    $this->withoutVite()
        ->get('/help')
        ->assertOk()
        ->assertSee('Pointing your domain at us', false)
        ->assertDontSee('Internal runbook', false);
});

it('shows an article and refuses one that is not published', function (): void {
    $published = KbArticle::factory()->create();
    $draft = KbArticle::factory()->draft()->create();

    $this->withoutVite()->get('/help/'.$published->slug)->assertOk();
    // Not 403: a 403 would confirm the draft is there.
    $this->withoutVite()->get('/help/'.$draft->slug)->assertNotFound();
});

it('never lets an article body reach the page as markup', function (): void {
    $article = KbArticle::factory()->create([
        'body' => 'Run <script>alert(document.cookie)</script> and restart.',
    ]);

    $this->withoutVite()
        ->get('/help/'.$article->slug)
        ->assertOk()
        ->assertDontSee('<script>alert(document.cookie)</script>', false);
});

it('counts a read once the article is shown', function (): void {
    $article = KbArticle::factory()->create(['view_count' => 0]);

    $this->withoutVite()->get('/help/'.$article->slug)->assertOk();

    expect($article->fresh()?->view_count)->toBe(1);
});
