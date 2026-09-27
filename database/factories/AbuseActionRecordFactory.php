<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Security\AbuseAction;
use App\Domain\Security\AbuseActionState;
use App\Infrastructure\Security\Models\AbuseActionRecord;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AbuseActionRecord>
 */
final class AbuseActionRecordFactory extends Factory
{
    protected $model = AbuseActionRecord::class;

    public function definition(): array
    {
        return [
            'action' => AbuseAction::ContactCustomer,
            'state' => AbuseActionState::Manual,
            'reason' => 'First warning, seven days to fix it.',
        ];
    }
}
