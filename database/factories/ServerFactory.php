<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Provisioning\ServerStatus;
use App\Infrastructure\Provisioning\Models\Server;
use App\Infrastructure\Provisioning\Models\ServerGroup;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Server>
 */
final class ServerFactory extends Factory
{
    protected $model = Server::class;

    public function definition(): array
    {
        return [
            'server_group_id' => fn (): string => ServerGroup::factory()->create()->id,
            'organization_id' => fn (array $attributes): string => $this->organizationOf(
                (string) $attributes['server_group_id'],
            ),
            'name' => 'node-'.Str::lower(Str::random(5)),
            'module' => 'manual',
            'hostname' => fake()->domainName(),
            'ip_address' => fake()->ipv4(),
            'port' => 2087,
            'secure' => true,
            'username' => 'root',
            'secret' => Str::random(32),
            'status' => ServerStatus::Active->value,
            'region' => null,
            'max_services' => 0,
            'weight' => 1,
            'health' => 'unknown',
        ];
    }

    public function inGroup(ServerGroup $group): self
    {
        return $this->state(fn (): array => [
            'server_group_id' => $group->id,
            'organization_id' => $group->organization_id,
        ]);
    }

    public function status(ServerStatus $status): self
    {
        return $this->state(fn (): array => ['status' => $status->value]);
    }

    public function capacity(int $maximum): self
    {
        return $this->state(fn (): array => ['max_services' => $maximum]);
    }

    private function organizationOf(string $groupId): string
    {
        return ServerGroup::query()
            ->withoutGlobalScope('organization')
            ->whereKey($groupId)
            ->firstOrFail()
            ->organization_id;
    }
}
