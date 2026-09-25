<?php

declare(strict_types=1);

use App\Application\Access\SyncPermissions;
use App\Domain\Access\PermissionRegistry;
use App\Domain\Access\SystemRole;
use App\Domain\Organizations\OrganizationType;
use App\Domain\Support\ArticleVisibility;
use App\Infrastructure\Content\Models\Announcement;
use App\Infrastructure\Content\Models\KbArticle;
use App\Infrastructure\Content\Models\KbCategory;
use App\Infrastructure\Identity\Models\StaffUser;
use App\Infrastructure\Organizations\Models\Organization;
use App\Support\Organizations\OrganizationContext;
use Database\Seeders\ProviderOrganizationSeeder;
use Database\Seeders\SystemRoleSeeder;

/**
 * Announcements and the knowledge base, driven rather than rendered.
 *
 * These two screens write the things every customer reads, and none of their
 * seven endpoints had ever been called by a test. The payloads below are the
 * ones the forms actually send — `datetime-local` gives `YYYY-MM-DDTHH:mm`, an
 * untouched optional field gives an empty string, and a number input gives a
 * string — because those are the three shapes that turn a rule into a refusal
 * nobody expected.
 */
beforeEach(function (): void {
    $this->withoutVite();

    $this->seed(ProviderOrganizationSeeder::class);
    app(SyncPermissions::class)->handle(app(PermissionRegistry::class));
    $this->seed(SystemRoleSeeder::class);

    $this->provider = Organization::query()
        ->where('type', OrganizationType::Provider->value)
        ->firstOrFail();

    app(OrganizationContext::class)->set($this->provider->id);

    $this->staff = StaffUser::factory()->create(['organization_id' => $this->provider->id]);
    $this->staff->assignRole(SystemRole::Administrator);
    $this->staff = $this->staff->fresh();
});

it('publishes, edits and removes an announcement', function (): void {
    $this->actingAs($this->staff, 'staff')
        ->post('/admin/content/announcements', [
            'title' => 'Maintenance on Sunday',
            'body' => 'The storefront will be closed between 02:00 and 04:00.',
            'visibility' => ArticleVisibility::Public->value,
            // What the form sends: the control's own format, and empty for the
            // field nobody filled in.
            'published_at' => '2026-10-01T09:00',
            'expires_at' => '',
            'is_pinned' => true,
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    $announcement = Announcement::query()->where('title', 'Maintenance on Sunday')->first();

    expect($announcement)->not->toBeNull()
        ->and($announcement->is_pinned)->toBeTrue()
        ->and($announcement->published_at)->not->toBeNull()
        ->and($announcement->expires_at)->toBeNull();

    $this->actingAs($this->staff, 'staff')
        ->put('/admin/content/announcements/'.$announcement->id, [
            'title' => 'Maintenance moved to Monday',
            'body' => 'Rescheduled.',
            'visibility' => ArticleVisibility::Public->value,
            'published_at' => '2026-10-02T09:00',
            'expires_at' => '2026-10-03T09:00',
            'is_pinned' => false,
        ])
        ->assertRedirect();

    expect($announcement->fresh()->title)->toBe('Maintenance moved to Monday')
        ->and($announcement->fresh()->is_pinned)->toBeFalse()
        ->and($announcement->fresh()->expires_at)->not->toBeNull();

    $this->actingAs($this->staff, 'staff')
        ->delete('/admin/content/announcements/'.$announcement->id)
        ->assertRedirect();

    expect(Announcement::query()->whereKey($announcement->id)->exists())->toBeFalse();
});

/**
 * The field's hint says "Leave empty to publish now", and it has to be true.
 *
 * Stored as null, the announcement was invisible to everybody:
 * `scopeVisible` wants a date that has passed, so the operator saw their own
 * announcement in the admin list and no customer ever saw it anywhere. The
 * screen looked like it had worked.
 */
it('publishes an announcement with no date the moment it is written', function (): void {
    $this->actingAs($this->staff, 'staff')
        ->post('/admin/content/announcements', [
            'title' => 'Nothing scheduled',
            'body' => 'Written now, read now.',
            'visibility' => ArticleVisibility::Public->value,
            'published_at' => '',
            'expires_at' => '',
            'is_pinned' => false,
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    $announcement = Announcement::query()->where('title', 'Nothing scheduled')->first();

    expect($announcement)->not->toBeNull()
        ->and($announcement->published_at)->not->toBeNull()
        ->and(Announcement::query()->publiclyVisible()->whereKey($announcement->id)->exists())
        ->toBeTrue();
});

it('refuses an announcement that expires before it is published', function (): void {
    $this->actingAs($this->staff, 'staff')
        ->post('/admin/content/announcements', [
            'title' => 'Backwards',
            'body' => 'Nope.',
            'visibility' => ArticleVisibility::Public->value,
            'published_at' => '2026-10-05T09:00',
            'expires_at' => '2026-10-01T09:00',
        ])
        ->assertSessionHasErrors('expires_at');

    expect(Announcement::query()->count())->toBe(0);
});

it('writes a category and then an article in it, edits it and deletes it', function (): void {
    $this->actingAs($this->staff, 'staff')
        ->post('/admin/content/categories', [
            'name' => 'Billing',
            'description' => 'Invoices, payments and refunds.',
        ])
        ->assertRedirect();

    $category = KbCategory::query()->where('name', 'Billing')->first();

    expect($category)->not->toBeNull();

    $this->actingAs($this->staff, 'staff')
        ->post('/admin/content/articles', [
            'title' => 'How to pay an invoice',
            'excerpt' => '',
            'body' => 'Open the invoice and press Pay.',
            'category_id' => $category->id,
            'visibility' => ArticleVisibility::Public->value,
            'published_at' => '',
            // A number input hands over a string, which is what the rule has
            // to accept.
            'position' => '0',
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    $article = KbArticle::query()->where('title', 'How to pay an invoice')->first();

    expect($article)->not->toBeNull()
        ->and($article->category_id)->toBe($category->id)
        ->and($article->excerpt)->toBeNull();

    $this->actingAs($this->staff, 'staff')
        ->put('/admin/content/articles/'.$article->id, [
            'title' => 'How to pay an invoice by card',
            'excerpt' => 'The short version.',
            'body' => 'Open the invoice and press Pay.',
            'category_id' => '',
            'visibility' => ArticleVisibility::Customers->value,
            'published_at' => '2026-10-01T09:00',
            'position' => '3',
        ])
        ->assertRedirect();

    expect($article->fresh()->title)->toBe('How to pay an invoice by card')
        ->and($article->fresh()->category_id)->toBeNull()
        ->and($article->fresh()->position)->toBe(3);

    $this->actingAs($this->staff, 'staff')
        ->delete('/admin/content/articles/'.$article->id)
        ->assertRedirect();

    expect(KbArticle::query()->whereKey($article->id)->exists())->toBeFalse();
});

it('refuses both screens to somebody without the content permissions', function (): void {
    $reader = StaffUser::factory()->create(['organization_id' => $this->provider->id]);

    $this->actingAs($reader, 'staff')
        ->post('/admin/content/announcements', [
            'title' => 'Nope',
            'body' => 'Nope',
            'visibility' => ArticleVisibility::Public->value,
        ])
        ->assertForbidden();

    $this->actingAs($reader, 'staff')
        ->post('/admin/content/articles', [
            'title' => 'Nope',
            'body' => 'Nope',
            'visibility' => ArticleVisibility::Public->value,
        ])
        ->assertForbidden();

    expect(Announcement::query()->count())->toBe(0)
        ->and(KbArticle::query()->count())->toBe(0);
});
