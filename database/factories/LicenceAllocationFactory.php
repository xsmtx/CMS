<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Infrastructure\Provisioning\Models\Server;
use App\Infrastructure\Vendors\Models\LicenceAllocation;
use App\Infrastructure\Vendors\Models\LicencePool;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LicenceAllocation>
 */
final class LicenceAllocationFactory extends Factory
{
    protected $model = LicenceAllocation::class;

    public function definition(): array
    {
        return [
            'licence_pool_id' => fn (): string => LicencePool::factory()->create()->id,
            /*
             * Read from the pool rather than created on its own: an
             * allocation in a different organization from the pool it belongs
             * to is a row the boundary would hide from one side and show on
             * the other.
             */
            'organization_id' => fn (array $attributes): string => LicencePool::query()
                ->whereKey($attributes['licence_pool_id'])
                ->firstOrFail()
                ->organization_id,
            'server_id' => null,
            'server_name' => 'web-1',
        ];
    }

    /**
     * On a machine, with its name copied the way the controller copies it.
     */
    public function on(Server $server): self
    {
        return $this->state(fn (): array => [
            'server_id' => $server->id,
            'server_name' => $server->name,
        ]);
    }

    /**
     * A seat on a machine that has left the fleet: the id is gone and the
     * name is all that is left, which is exactly what the screen reads.
     */
    public function orphaned(string $name = 'db3'): self
    {
        return $this->state(fn (): array => ['server_id' => null, 'server_name' => $name]);
    }
}
