<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Dcim\RemoteHandsState;
use App\Infrastructure\Dcim\Models\RemoteHandsTask;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RemoteHandsTask>
 */
final class RemoteHandsTaskFactory extends Factory
{
    protected $model = RemoteHandsTask::class;

    public function definition(): array
    {
        return [
            'summary' => 'Replace the failed disk in bay 4',
            'instructions' => 'The amber light is on bay 4. Take the disk out, put the spare in, and read both serials back.',
            'state' => RemoteHandsState::Requested,
            'requested_at' => CarbonImmutable::now()->subHours(2),
        ];
    }

    public function in(RemoteHandsState $state): self
    {
        return $this->state(fn (): array => ['state' => $state]);
    }
}
