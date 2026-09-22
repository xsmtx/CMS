<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Infrastructure\Crm\Models\Note;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Note>
 */
final class NoteFactory extends Factory
{
    protected $model = Note::class;

    public function definition(): array
    {
        return [
            'author_label' => fake()->name(),
            'body' => fake()->paragraph(),
            'is_customer_visible' => false,
            'is_pinned' => false,
        ];
    }

    public function customerVisible(): static
    {
        return $this->state(fn (): array => ['is_customer_visible' => true]);
    }
}
