<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Infrastructure\Capability;
use App\Infrastructure\Organizations\Models\Organization;
use App\Infrastructure\Resources\Models\ResourceAdapter;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ResourceAdapter>
 */
final class ResourceAdapterFactory extends Factory
{
    protected $model = ResourceAdapter::class;

    public function definition(): array
    {
        return [
            'organization_id' => fn (): string => Organization::factory()->create()->id,
            'adapter_key' => 'probe-'.Str::lower(Str::random(5)),
            'name' => 'Probe',
            'vendor' => 'InfraCMS',
            'capabilities' => [Capability::MetricsRead->value],
            'enabled' => true,
            'writes_enabled' => false,
        ];
    }

    public function keyed(string $key): self
    {
        return $this->state(fn (): array => ['adapter_key' => $key]);
    }

    /**
     * @param  list<Capability>  $capabilities
     */
    public function declaring(array $capabilities): self
    {
        return $this->state(fn (): array => [
            'capabilities' => array_map(
                static fn (Capability $capability): string => $capability->value,
                $capabilities,
            ),
        ]);
    }

    public function writable(): self
    {
        return $this->state(fn (): array => ['writes_enabled' => true]);
    }

    public function disabled(): self
    {
        return $this->state(fn (): array => ['enabled' => false]);
    }
}
