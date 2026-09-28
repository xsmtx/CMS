<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Intelligence\ProposalState;
use App\Domain\Intelligence\ReconciliationClass;
use App\Domain\Intelligence\RemediationAction;
use App\Infrastructure\Intelligence\Models\RemediationProposal;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RemediationProposal>
 */
final class RemediationProposalFactory extends Factory
{
    protected $model = RemediationProposal::class;

    public function definition(): array
    {
        return [
            'action' => RemediationAction::AcceptSuspension,
            'state' => ProposalState::Proposed,
            'finding_class' => ReconciliationClass::Drift,
            'proposed_at' => CarbonImmutable::now(),
        ];
    }

    public function doing(RemediationAction $action): self
    {
        return $this->state(fn (): array => ['action' => $action]);
    }

    public function in(ProposalState $state): self
    {
        return $this->state(fn (): array => ['state' => $state]);
    }
}
