<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Automation\DunningAction;
use App\Domain\Notifications\NotificationEvent;
use App\Infrastructure\Automation\Models\DunningStep;
use App\Infrastructure\Organizations\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DunningStep>
 */
final class DunningStepFactory extends Factory
{
    protected $model = DunningStep::class;

    public function definition(): array
    {
        return [
            'organization_id' => fn (): string => Organization::query()
                ->withoutGlobalScope('organization')
                ->whereNull('parent_id')
                ->firstOrFail()
                ->id,
            'offset_days' => -3,
            'action' => DunningAction::Notify->value,
            'event' => NotificationEvent::InvoiceIssued->value,
            'is_active' => true,
            'position' => 0,
        ];
    }

    public function notify(int $offsetDays, NotificationEvent $event): self
    {
        return $this->state(fn (): array => [
            'offset_days' => $offsetDays,
            'action' => DunningAction::Notify->value,
            'event' => $event->value,
            'position' => $offsetDays + 100,
        ]);
    }

    public function lateFee(int $offsetDays): self
    {
        return $this->state(fn (): array => [
            'offset_days' => $offsetDays,
            'action' => DunningAction::LateFee->value,
            'event' => null,
            'position' => $offsetDays + 100,
        ]);
    }

    public function suspend(int $offsetDays): self
    {
        return $this->state(fn (): array => [
            'offset_days' => $offsetDays,
            'action' => DunningAction::Suspend->value,
            'event' => null,
            'position' => $offsetDays + 100,
        ]);
    }

    public function terminate(int $offsetDays): self
    {
        return $this->state(fn (): array => [
            'offset_days' => $offsetDays,
            'action' => DunningAction::Terminate->value,
            'event' => null,
            'position' => $offsetDays + 100,
        ]);
    }
}
