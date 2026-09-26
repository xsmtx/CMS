<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Network\IpPrefix;
use App\Infrastructure\Network\Models\IpPool;
use App\Infrastructure\Network\Models\IpPrefixRecord;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<IpPrefixRecord>
 */
final class IpPrefixRecordFactory extends Factory
{
    protected $model = IpPrefixRecord::class;

    public function definition(): array
    {
        return IpPrefixRecord::columnsFor(IpPrefix::parse('192.0.2.0/24'));
    }

    public function inPool(IpPool $pool): self
    {
        return $this->state(fn (): array => [
            'organization_id' => $pool->organization_id,
            'ip_pool_id' => $pool->id,
        ]);
    }

    public function of(string $cidr): self
    {
        return $this->state(fn (): array => IpPrefixRecord::columnsFor(IpPrefix::parse($cidr)));
    }
}
