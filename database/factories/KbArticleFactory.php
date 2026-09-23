<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Support\ArticleVisibility;
use App\Infrastructure\Content\Models\KbArticle;
use App\Infrastructure\Content\Models\KbCategory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<KbArticle>
 */
final class KbArticleFactory extends Factory
{
    protected $model = KbArticle::class;

    public function definition(): array
    {
        $title = Str::ucfirst(fake()->unique()->sentence(4));

        return [
            'category_id' => fn (): string => KbCategory::factory()->create()->id,
            'organization_id' => fn (array $attributes): string => KbCategory::query()
                ->withoutGlobalScope('organization')
                ->whereKey((string) $attributes['category_id'])
                ->firstOrFail()
                ->organization_id,
            'title' => $title,
            'slug' => Str::slug($title).'-'.Str::lower(Str::random(4)),
            'excerpt' => fake()->sentence(),
            'body' => fake()->paragraphs(3, true),
            'visibility' => ArticleVisibility::Public->value,
            'published_at' => now()->subHour(),
            'position' => 0,
        ];
    }

    public function inCategory(KbCategory $category): self
    {
        return $this->state(fn (): array => [
            'category_id' => $category->id,
            'organization_id' => $category->organization_id,
        ]);
    }

    public function draft(): self
    {
        return $this->state(fn (): array => [
            'visibility' => ArticleVisibility::Draft->value,
            'published_at' => null,
        ]);
    }

    public function customersOnly(): self
    {
        return $this->state(fn (): array => ['visibility' => ArticleVisibility::Customers->value]);
    }
}
