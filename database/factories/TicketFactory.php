<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Support\TicketPriority;
use App\Domain\Support\TicketStatus;
use App\Infrastructure\Crm\Models\Customer;
use App\Infrastructure\Support\Models\Department;
use App\Infrastructure\Support\Models\Ticket;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Ticket>
 */
final class TicketFactory extends Factory
{
    protected $model = Ticket::class;

    public function definition(): array
    {
        return [
            'customer_id' => fn (): string => Customer::factory()->create()->id,
            'organization_id' => fn (array $attributes): string => Customer::query()
                ->withoutGlobalScope('organization')
                ->whereKey((string) $attributes['customer_id'])
                ->firstOrFail()
                ->organization_id,
            'number' => 'TKT-'.Str::upper(Str::random(8)),
            'subject' => Str::ucfirst(fake()->sentence(5)),
            'status' => TicketStatus::Open->value,
            'priority' => TicketPriority::Normal->value,
        ];
    }

    public function forCustomer(Customer $customer): self
    {
        return $this->state(fn (): array => [
            'customer_id' => $customer->id,
            'organization_id' => $customer->organization_id,
        ]);
    }

    public function inDepartment(Department $department): self
    {
        return $this->state(fn (): array => ['department_id' => $department->id]);
    }

    public function status(TicketStatus $status): self
    {
        return $this->state(fn (): array => ['status' => $status->value]);
    }

    public function priority(TicketPriority $priority): self
    {
        return $this->state(fn (): array => ['priority' => $priority->value]);
    }

    /**
     * A ticket whose first-response promise has already run out.
     */
    public function breaching(): self
    {
        return $this->state(fn (): array => [
            'status' => TicketStatus::Open->value,
            'first_response_due_at' => now()->subHour(),
            'first_responded_at' => null,
        ]);
    }
}
