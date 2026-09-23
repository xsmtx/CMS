<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Platform\TodoStatus;
use App\Infrastructure\Platform\Models\TodoItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TodoItem>
 */
final class TodoItemFactory extends Factory
{
    protected $model = TodoItem::class;

    public function definition(): array
    {
        return [
            'title' => 'Ring the registrar about the transfer',
            'body' => null,
            'status' => TodoStatus::Pending->value,
            'due_on' => now()->addDays(3)->toDateString(),
        ];
    }

    public function done(): self
    {
        return $this->state(fn (): array => [
            'status' => TodoStatus::Done->value,
            'completed_at' => now(),
        ]);
    }
}
