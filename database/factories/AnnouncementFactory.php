<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Support\ArticleVisibility;
use App\Infrastructure\Content\Models\Announcement;
use App\Infrastructure\Organizations\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Announcement>
 */
final class AnnouncementFactory extends Factory
{
    protected $model = Announcement::class;

    public function definition(): array
    {
        $title = Str::ucfirst(fake()->unique()->sentence(4));

        return [
            'organization_id' => fn (): string => Organization::query()
                ->withoutGlobalScope('organization')
                ->whereNull('parent_id')
                ->firstOrFail()
                ->id,
            'title' => $title,
            'slug' => Str::slug($title).'-'.Str::lower(Str::random(4)),
            'body' => fake()->paragraphs(2, true),
            'visibility' => ArticleVisibility::Public->value,
            'published_at' => now()->subHour(),
            'is_pinned' => false,
        ];
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

    public function expired(): self
    {
        return $this->state(fn (): array => ['expires_at' => now()->subDay()]);
    }

    public function scheduled(): self
    {
        return $this->state(fn (): array => ['published_at' => now()->addWeek()]);
    }
}
