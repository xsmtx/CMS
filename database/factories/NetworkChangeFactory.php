<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Network\NetworkChangeState;
use App\Infrastructure\Network\Models\NetworkChange;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<NetworkChange>
 */
final class NetworkChangeFactory extends Factory
{
    protected $model = NetworkChange::class;

    public function definition(): array
    {
        return [
            'state' => NetworkChangeState::Requested,
            'summary' => 'Open 443 to the new web tier',
            'reason' => 'The new servers are live and nothing reaches them.',
            'requires_approval' => true,
            'intended' => "config firewall policy\n    edit 12\nend\n",
        ];
    }

    /**
     * Named `inState` rather than `state`, which is `Factory`'s own method and
     * would be overridden here with a different signature — the kind of clash
     * that compiles and then behaves oddly for every other caller.
     */
    public function inState(NetworkChangeState $state): self
    {
        return $this->state(fn (): array => ['state' => $state]);
    }
}
