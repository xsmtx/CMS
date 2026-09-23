<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Automation\ItemOutcome;
use App\Infrastructure\Automation\Models\AutomationRunItemRecord;
use App\Infrastructure\Automation\Models\AutomationRunRecord;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AutomationRunItemRecord>
 */
final class AutomationRunItemRecordFactory extends Factory
{
    protected $model = AutomationRunItemRecord::class;

    public function definition(): array
    {
        return [
            'run_id' => fn (): string => AutomationRunRecord::factory()->create()->id,
            'outcome' => ItemOutcome::Changed->value,
            'subject_label' => 'INV-000001',
            'created_at' => now(),
        ];
    }
}
