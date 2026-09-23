<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Infrastructure\Support\Models\Ticket;
use App\Infrastructure\Support\Models\TicketReply;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TicketReply>
 */
final class TicketReplyFactory extends Factory
{
    protected $model = TicketReply::class;

    public function definition(): array
    {
        return [
            'ticket_id' => fn (): string => Ticket::factory()->create()->id,
            'organization_id' => fn (array $attributes): string => Ticket::query()
                ->withoutGlobalScope('organization')
                ->whereKey((string) $attributes['ticket_id'])
                ->firstOrFail()
                ->organization_id,
            'author_type' => TicketReply::AUTHOR_CUSTOMER,
            'author_name' => fake()->name(),
            'body' => fake()->paragraph(),
            'is_internal' => false,
            'created_at' => now(),
        ];
    }

    public function forTicket(Ticket $ticket): self
    {
        return $this->state(fn (): array => [
            'ticket_id' => $ticket->id,
            'organization_id' => $ticket->organization_id,
        ]);
    }

    public function fromStaff(): self
    {
        return $this->state(fn (): array => ['author_type' => TicketReply::AUTHOR_STAFF]);
    }

    public function internal(): self
    {
        return $this->state(fn (): array => [
            'author_type' => TicketReply::AUTHOR_STAFF,
            'is_internal' => true,
        ]);
    }
}
