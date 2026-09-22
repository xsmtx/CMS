<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Billing\GatewayEventType;
use App\Domain\Organizations\OrganizationType;
use App\Infrastructure\Billing\Models\GatewayEventRecord;
use App\Infrastructure\Organizations\Models\Organization;
use App\Support\Organizations\OrganizationContext;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<GatewayEventRecord>
 */
final class GatewayEventRecordFactory extends Factory
{
    protected $model = GatewayEventRecord::class;

    public function definition(): array
    {
        return [
            'organization_id' => fn (): string => $this->owningOrganization()->id,
            'gateway' => 'stripe',
            'event_id' => 'evt_'.Str::lower(Str::random(16)),
            'type' => GatewayEventType::PaymentCompleted->value,
            'payment_reference' => null,
            'outcome' => 'received',
            'error' => null,
            'payload' => [],
            'received_at' => now(),
            'processed_at' => null,
        ];
    }

    private function owningOrganization(): Organization
    {
        $boundary = app(OrganizationContext::class)->id();

        if ($boundary !== null) {
            return Organization::query()->withoutGlobalScope('organization')->findOrFail($boundary);
        }

        return Organization::query()
            ->withoutGlobalScope('organization')
            ->where('type', OrganizationType::Provider->value)
            ->first() ?? Organization::factory()->provider()->create();
    }
}
