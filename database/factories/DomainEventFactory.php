<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Domains\DomainOperation;
use App\Domain\Provisioning\OperationOutcome;
use App\Infrastructure\Domains\Models\Domain;
use App\Infrastructure\Domains\Models\DomainEvent;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DomainEvent>
 */
final class DomainEventFactory extends Factory
{
    protected $model = DomainEvent::class;

    public function definition(): array
    {
        return [
            'domain_id' => fn (): string => Domain::factory()->create()->id,
            'organization_id' => fn (array $attributes): string => Domain::query()
                ->withoutGlobalScope('organization')
                ->whereKey((string) $attributes['domain_id'])
                ->firstOrFail()
                ->organization_id,
            'operation' => DomainOperation::Register->value,
            'outcome' => OperationOutcome::Succeeded->value,
            'occurred_at' => now(),
        ];
    }

    public function forDomain(Domain $domain): self
    {
        return $this->state(fn (): array => [
            'domain_id' => $domain->id,
            'organization_id' => $domain->organization_id,
        ]);
    }
}
