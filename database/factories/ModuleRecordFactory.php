<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Modules\ModuleState;
use App\Domain\Modules\ModuleType;
use App\Infrastructure\Modules\Models\ModuleRecord;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ModuleRecord>
 */
final class ModuleRecordFactory extends Factory
{
    protected $model = ModuleRecord::class;

    public function definition(): array
    {
        $slug = 'example-'.Str::lower(Str::random(6));

        return [
            'slug' => $slug,
            'name' => 'Example Module',
            'type' => ModuleType::Fraud->value,
            'version' => '1.0.0',
            'provider' => 'InfraCMS',
            'path' => 'infracms/'.$slug,
            'state' => ModuleState::Installed->value,
            'config' => null,
            'installed_at' => now(),
        ];
    }

    /**
     * Named `inState` rather than `state`: `Factory::state()` is the
     * framework's own, and overriding it here would break every other
     * state on this factory.
     */
    public function inState(ModuleState $state): self
    {
        return $this->state(fn (): array => ['state' => $state->value]);
    }

    public function enabled(): self
    {
        return $this->state(fn (): array => [
            'state' => ModuleState::Enabled->value,
            'enabled_at' => now(),
        ]);
    }
}
