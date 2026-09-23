<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Infrastructure\Support\Models\Ticket;
use App\Infrastructure\Support\Models\TicketAttachment;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<TicketAttachment>
 */
final class TicketAttachmentFactory extends Factory
{
    protected $model = TicketAttachment::class;

    public function definition(): array
    {
        return [
            'ticket_id' => fn (): string => Ticket::factory()->create()->id,
            'organization_id' => fn (array $attributes): string => Ticket::query()
                ->withoutGlobalScope('organization')
                ->whereKey((string) $attributes['ticket_id'])
                ->firstOrFail()
                ->organization_id,
            'original_name' => 'screenshot.png',
            'path' => 'tickets/'.Str::lower(Str::random(12)).'.png',
            'mime_type' => 'image/png',
            'size_bytes' => 24_576,
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
}
